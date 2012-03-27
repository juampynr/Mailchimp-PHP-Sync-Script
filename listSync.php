<?php
/**
 * Generic sync script to share emails between your web application and Mailchimp
 * Submits email statuses since last time it was executed
 * NOTE: Mailchimp api times are in GMT+0, set the right value to the $gmt variable if your server is not GMT+0
 */
chdir(dirname(__FILE__)); // Set dir so relative paths work on cron execution
require_once 'MCAPI.class.php';

$gmt = 0; // if you have a negative GMT, then set a negative number here such as -4
$database = array(
  'host'     => '',
  'username' => '',
  'password' => '',
  'dbname'   => '',
);

try {
  $current_time = time();

  // 1. Connect to database
  mysql_connect($database['host'], $database['username'], $database['password']);
  $link = mysql_select_db($database['dbname']);
  if (!$link) {
    throw new Exception('Could not select database');
  }

  // 2. Extract last sync date
  $query = 'SELECT * FROM mailchimp';
  $result = mysql_query($query);
  $mailchimp = mysql_fetch_object($result);
  if (!$mailchimp) {
    throw new Exception('Could not obtain mailchimp account details.');
  }
  print 'Starting at ' . date('r', time()) . '. Last execution was at ' . date('r', $mailchimp->last_sync) . "\n";

  // 3. Update records of people who unsubscribed throuh Mailchimp
  // deleted users in Mailchimp are not listed in the API so we do not take them into account
  $api = new MCAPI($mailchimp->apikey);
  $retval = $api->listMembers($mailchimp->listid, 'unsubscribed', date('Y-m-d H:i:s', $mailchimp->last_sync - $gmt));
  if($api->errorCode) {
    throw new Exception(
     'Unable to load unsubscribed members!\n\tCode = ' . $api->errorCode . '\n\tMsg = '.$api->errorMessage);
  } else {
    print $retval['total'] . " emails unsubscribed at Mailchimp\n";
    foreach($retval['data'] as $member) {
      mysql_query('UPDATE youruserstable set newsletter = 0, last_sync = ' . (strtotime($member['timestamp']) + $gmt) .
                  ' where email = "' . $member['email'] . '"');
    }
  }

  // 4. Update records of people who subscribed throuh Mailchimp
  $retval = $api->listMembers($mailchimp->listid, 'subscribed', date('Y-m-d H:i:s', $mailchimp->last_sync - $gmt));
  if($api->errorCode) {
    throw new Exception(
     'Unable to load subscribed members!\n\tCode = ' . $api->errorCode . '\n\tMsg = '.$api->errorMessage);
  } else {
    print $retval['total'] . " emails subscribed or were deleted at Mailchimp\n";
    foreach($retval['data'] as $member) {
      mysql_query('UPDATE youruserstable set newsletter = 1, last_sync = ' . (strtotime($member['timestamp']) + $gmt) .
                  ' where email = "' . $member['email'] . '"');
    }
  }

  // 5. Obtain the list of users to subscribe
  $query = 'SELECT firstname, lastname, email ' .
           'FROM youruserstable ' .
           'WHERE newsletter = 1 and last_sync >= ' . $mailchimp->last_sync;
  $result = mysql_query($query);
  $batch = array();
  while ($row = mysql_fetch_object($result)) {
    $batch[] = array(
     'EMAIL' =>  $row->email,
     'FNAME' =>  $row->firstname,
     'LNAME' =>  $row->lastname,
    );
  }

  if (count($batch)) {
    // Connect to mailchimp and subscribe users
    $optin = FALSE; //yes, send optin emails
    $up_exist = true; // yes, update currently subscribed users
    $replace_int = false; // no, add interest, don't replace
    $vals = $api->listBatchSubscribe($mailchimp->listid, $batch, $optin, $up_exist, $replace_int);
    if ($api->errorCode) {
      throw new Exception(
        'Batch Subscribe failed!\n' .
        'code:' . $api->errorCode . '\n' .
        'msg :' . $api->errorMessage . '\n'
      );
    } else {
      echo "Subscribed users to Mailchimp. Results are:\n";
      echo "added:   " . $vals['add_count'] . "\n";
      echo "updated: " . $vals['update_count'] . "\n";
      echo "errors:  " . $vals['error_count'] . "\n";
      foreach($vals['errors'] as $val){
        print_r($val);
      }
    }
  }

  // 4. Extract the list of users to unsubscribe
  $query = 'SELECT firstname, lastname, email ' .
           'FROM youruserstable ' .
           'WHERE newsletter = 0 AND last_sync >= ' . $mailchimp->last_sync;
  $result = mysql_query($query);
  $emails = array();
  while ($row = mysql_fetch_object($result)) {
    $emails [] = $row->email;
  }

  if (count($emails)) {
    $delete = false; //don't completely remove the emails
    $bye = false; // yes, send a goodbye email
    $notify = false; // no, don't tell me I did this
    $vals = $api->listBatchUnsubscribe($mailchimp->listid, $emails, $delete, $bye, $notify);
    if ($api->errorCode) {
      throw new Exception(
        'Batch Unsubscribe failed!\n' .
        'code:' . $api->errorCode . '\n' .
        'msg :' . $api->errorMessage . '\n'
      );
    } else {
      echo "Unsubscribed users to Mailchimp. Results are:\n";
      echo "success:" . $vals['success_count'] . "\n";
      echo "errors:" . $vals['error_count'] . "\n";
      foreach($vals['errors'] as $val) {
        print_r($val);
      }
    }
  }

  // 5. Update last_synchronization date to the current time
  $result = mysql_query('UPDATE mailchimp set last_synchronisation = ' . $current_time);
} catch (Exception $e) {
  print_r($e);
}

