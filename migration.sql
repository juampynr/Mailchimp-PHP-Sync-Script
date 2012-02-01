/* This is the basic database structure that you need to run the script
   Reat it carefully and update it to suit your scenario */

# Add a field in your users table to mark the last time the user changed his subscription
# You should also add the logic that updates this field when a user changes his subscription status in your website.
ALTER TABLE youruserstable ADD COLUMN last_sync INT(11) DEFAULT 0;
UPDATE yourusertable set last_sync = UNIX_TIMESTAMP();

# Create a table to store Mailchimp account details
CREATE TABLE mailchimp(last_sync INT(11) DEFAULT 0, apikey varchar(255) NULL, listid varchar(255) null, apiurl varchar(255) NULL);
INSERT INTO mailchimp(last_sync, apikey, listid, apiurl) values(0, 'yourapikey', 'yourlistid', 'yourapiurl');
