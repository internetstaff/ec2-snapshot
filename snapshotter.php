#!/usr/bin/php
<?php

define('SNAPSHOT_SLEEP_SEC', 15);         // Seconds to wait in snapshot wait loop
define('DAYS_BEFORE_WEEKLY', 7);          // How many daily snapshots until we start keeping only one per week?
define('DAYS_BEFORE_DELETE', 30);         // How many days old before we delete a snapshot?
define('DESCRIPTION_PREFIX', 'Backup ');  // Description prefix for snapshot
define('FORCE_SNAPSHOT_TAG', 'SNAPSHOT'); // Tag whose existence forces a snapshot despite other criteria

require_once 'AWSSDKforPHP/sdk.class.php';

$ec2 = new AmazonEC2();

if (sizeof($argv) < 2) {
  echo "You must specify a region url ex: ec2.us-east-1.amazonaws.com\n";
  return;
}

$region = $argv[1];
$ec2->set_region($region);
echo "Running in region:", $region, "\n";

// Take snapshots

$result = $ec2->describe_volumes();
$scriptStartTime = microtime(true);

if ($result->isOK()) {
  foreach ($result->body->volumeSet->item as $volume) {
    $instanceName = instanceName($volume);
    echo "\nInstance: ", $instanceName, " Volume: ", $volume->volumeId," (", $volume->size,"GB)\n";
    if (needsSnapshot($volume)) {
      takeSnapshot($volume, $instanceName);
    }    
  }
} else {
  echo "Failed to retrieve volumes.\n";
  return;
}

echo "All snapshots complete in ", number_format(microtime(true) - $scriptStartTime, 2), " seconds.\n";

echo "Cleaning snapshots.\n";
$scriptStartTime = microtime(true);

// Clean snapshots

$result = $ec2->describe_volumes();
if ($result->isOK()) {
  foreach ($result->body->volumeSet->item as $volume) {
    $instanceName = instanceName($volume);
    echo "\nInstance: ", $instanceName, " Volume: ", $volume->volumeId," (", $volume->size,"GB)\n";
    if (needsSnapshot($volume)) {
      $snapshots = getSnapshots($volume);
      if (!is_null($snapshots)) {
        $newest = $snapshots[0];
        $lastDateString = "";
        $i = 0;
        foreach ($snapshots as $snapshot) {
          $timestamp = strtotime($snapshot->startTime);
          $age = time() - $timestamp;
          $dateString = ($age > (DAYS_BEFORE_WEEKLY*86400)) ? 'Week ' . date("W    ", $timestamp) : date("d-M-Y", $timestamp);
          echo "Snapshot code: ", $dateString, " date: ", date('d-M-Y H:i', $timestamp), " \"", $snapshot->description,"\"";
        
          // Delete if we already have a weekly from that week, or we're over DAYS_BEFORE_DELETE days old
          if (($dateString == $lastDateString) || ($age > DAYS_BEFORE_DELETE * 86400)) {
            $delete = $ec2->delete_snapshot($snapshot->snapshotId);
            if ($delete->isOK()) {
              echo " - DELETED\n";
            } else {
              echo " - ERROR DELETING\n";
            }
          } else { 
            echo " - KEPT\n";
          }

          $lastDateString = $dateString;
        }
      } else {
        echo "No snapshots exist.\n";
      }
    } else {
      echo "No snapshots required.\n";
    }
  }
} else {
  echo "Failed to retrieve volumes.\n";
  return;
}

echo "Snapshot cleanup complete in ", number_format(microtime(true) - $scriptStartTime, 2), " seconds.\n";

// Sort snapshots by creation time
function sortSnapshots($a, $b) {
  $at = strtotime($a->startTime);
  $bt = strtotime($b->startTime);

  if ($at == $bt) {
    return 0;
  }
  return ($at < $bt) ? -1 : 1;
}

// Given a volume-id, return an array of snapshots
function getSnapshots($volume) {
  global $ec2;

  $result = $ec2->describe_snapshots(array('Filter'=>array(array('Name'=>'volume-id', 'Value'=>$volume->volumeId))));


  if ($result->isOK()) {
    $timestamp = 0;
    $snapshots = (array)$result->body->snapshotSet->children();
    if (!array_key_exists('item', $snapshots)) {
      return null;
    }
    $snapshots = $snapshots['item'];
    $filteredSnapshots = array();

    if (is_array($snapshots)) {
      usort($snapshots, 'sortSnapshots');
      foreach ($snapshots as $snapshot) {
        if ((substr($snapshot->description, 0, 7) == DESCRIPTION_PREFIX) && ($snapshot->status == 'completed')) {
           $filteredSnapshots[] = $snapshot;
        }
      }
    } else {
      $filteredSnapshots[] = $snapshots;
    }
    
    return $filteredSnapshots;

  } else {
    throw new Exception("Failed to retrieve snapshots.");
    return false;
  }
}

// Hang out until a snapshot is complete to avoid possible performance implications of taking a bunch at once
function waitSnapshot($snapshotId) { 
  global $ec2;

  echo "Waiting for snapshot: ", $snapshotId, "\n";
  $startTime = microtime(true);
  // Wait up to one hour
  while (microtime(true) - $startTime < 3600) {
    $result = $ec2->describe_snapshots(array('SnapshotId' => $snapshotId));
    if ($result->isOK()) {
      $snapshot = $result->body->snapshotSet->item;
      if ($snapshot->status == 'completed') {
        echo "Snapshot ", $snapshotId, " complete in ", 
          number_format(microtime(true) - $startTime, 2), " seconds.\n";
        break;
      } else if ($snapshot->status == 'error') {
        echo "Error creating snapshot ", $snapshotId, ".\n";
        break;
      }
    } else {
      echo "Error retrieving snapshot status.\n";
      break;
    }
    // Sleep for a while
    sleep(SNAPSHOT_SLEEP_SEC);
  }
  
}

// Given a volume-id take a snapshot and wait for it
function takeSnapshot($volume, $instanceName) {
  global $ec2;

  $result = $ec2->create_snapshot($volume->volumeId, 
    array('Description' => DESCRIPTION_PREFIX . $instanceName));

  if ($result->isOK()) {
    $snapshotId = $result->body->snapshotId;
    $status = $result->body->status;
    echo "Creating snapshot: ", $snapshotId, "\n";
    waitSnapshot($snapshotId);
  } else {
    echo "Failed to create snapshot on volume ", $volume, "\n";
  }
}

// Given a volume-id, return whether the instance is running or not
function instanceRunning($volume) {
  global $ec2;

  $instanceId = $volume->attachmentSet->item->instanceId;

  $result = $ec2->describe_instances(array('InstanceId' => $instanceId));

  if ($result->isOK()) {
    return ($result->body->reservationSet->item->instancesSet->item->instanceState->name == 'running');
  }

  return true; 
}

// Given a volume-id, return then instance name
function instanceName($volume) {
  global $ec2;

  $instanceId = $volume->attachmentSet->item->instanceId;
  $instanceName = 'Unknown';
  
  $result = $ec2->describe_instances(array('InstanceId' => $instanceId));

  if ($result->isOK()) {
    foreach ($result->body->reservationSet->item->instancesSet->item->tagSet->item as $tag) {
      if ($tag->key == 'Name') {
        $instanceName = $tag->value;
        $pos = strpos($instanceName, ' ');
        if ($pos) {
          $instanceName = substr($instanceName, 0, $pos);
        }
        break;
      }
    }
  }
  return $instanceName;
}

// Given a volume-id, return whether it meets the criteria to have a snapshot
function needsSnapshot($volume) {
  // Don't snapshot unmounted volumes
  if ($volume->status == 'in-use') {

    // Don't snapshot instances that are shut down
    if (!instanceRunning($volume)) {
      return false;
    }

    // Always snapshot system volumes
    if ($volume->attachmentSet->item->device == '/dev/sda1') {
      return true;
    } 

    // Snapshot anything with a SNAPSHOT tag
    if ($volume->tagSet) {
      foreach ($volume->tagSet->item as $tag) {
        if ($tag->key == FORCE_SNAPSHOT_TAG) {
          return true;
        }
      }
    }
 
  } 

  return false;
}

?>

