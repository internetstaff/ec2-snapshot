ec2-snapshot
============

This script will create a snapshot for every volume in an EC2 account that meets the criteria:

   1. Is "in-use" (attached)
   2. Is a system volume (/dev/sda1) OR
   3. Has a tag named "SNAPSHOT"
   4. Is attached to a running instance

This is because generally extra attached volumes are things we may not want to snapshot, like
a database.  However, some things are, so to avoid snapshotting very large database or other
volumes, we default to skipping them but allow them to be flagged on.

This script intentionally runs serially and waits for each snapshot to complete. This is to avoid
any potential performance impact affecting more than one instance at a time. 

Configuration
 
Create a ~/.aws/sdk/config.inc.php file per the PHP SDK specs

This script will use the default credentials.

Install SDK

sudo pear channel-discover pear.amazonwebservices.com
sudo pear install aws/sdk

User needs:

   "ec2:CreateSnapshot",
   "ec2:DeleteSnapshot",
   "ec2:DescribeSnapshots"

This is pretty raw an ugly. Help clean it up! :)

