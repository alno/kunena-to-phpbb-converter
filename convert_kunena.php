<?php

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
  exit;
}

include($phpbb_root_path . 'config.' . $phpEx);
unset($dbpasswd);

/**
* $convertor_data provides some basic information about this convertor which is
* used on the initial list of convertors and to populate the default settings
*/
$convertor_data = array(
  'forum_name'  => 'Kunena x.x.x',
  'version'   => '0.0.1',
  'phpbb_version' => '3.1.3',
  'author'    => '',
  'dbms'      => $dbms,
  'dbhost'    => $dbhost,
  'dbport'    => $dbport,
  'dbuser'    => $dbuser,
  'dbpasswd'    => '',
  'dbname'       => $dbname,
  'table_prefix'  => '',
  'forum_path'  => '.',
  'author_notes'  => '',
);

/**
* $test_file is the name of a file which is present on the source
* forum which can be used to check that the path specified by the
* user was correct
*/
$test_file = 'index.php';

/**
* $tables is a list of the tables (minus prefix) which we expect to find in the
* source forum. It is used to guess the prefix if the specified prefix is incorrect
*/
$tables = array(
  'users',
  'kunena_users',
  'kunena_categories',
  'kunena_topics',
  'kunena_messages',
  'kunena_messages_text',
);

/**
* If this is set then we are not generating the first page of information but getting the conversion information.
*/
if (!$get_info)
{

  // Overwrite maximum avatar width/height
  //@define('DEFAULT_AVATAR_X_CUSTOM', get_config_value('avatar_max_width'));
  //@define('DEFAULT_AVATAR_Y_CUSTOM', get_config_value('avatar_max_height'));

  // Check whether the user location field exists.
  //@define('USER_FROM_EXISTS', $helper->user_from_col_exists());


/**
* Description on how to use the convertor framework.
*
* 'schema' Syntax Description
*   -> 'target'     => Target Table. If not specified the next table will be handled
*   -> 'primary'    => Primary Key. If this is specified then this table is processed in batches
*   -> 'query_first'  => array('target' or 'src', Query to execute before beginning the process
*               (if more than one then specified as array))
*   -> 'function_first' => Function to execute before beginning the process (if more than one then specified as array)
*               (This is mostly useful if variables need to be given to the converting process)
*   -> 'test_file'    => This is not used at the moment but should be filled with a file from the old installation
*
*   // DB Functions
*   'distinct'  => Add DISTINCT to the select query
*   'where'   => Add WHERE to the select query
*   'group_by'  => Add GROUP BY to the select query
*   'left_join' => Add LEFT JOIN to the select query (if more than one joins specified as array)
*   'having'  => Add HAVING to the select query
*
*   // DB INSERT array
*   This one consist of three parameters
*   First Parameter:
*             The key need to be filled within the target table
*             If this is empty, the target table gets not assigned the source value
*   Second Parameter:
*             Source value. If the first parameter is specified, it will be assigned this value.
*             If the first parameter is empty, this only gets added to the select query
*   Third Parameter:
*             Custom Function. Function to execute while storing source value into target table.
*             The functions return value get stored.
*             The function parameter consist of the value of the second parameter.
*
*             types:
*               - empty string == execute nothing
*               - string == function to execute
*               - array == complex execution instructions
*
*   Complex execution instructions:
*   @todo test complex execution instructions - in theory they will work fine
*
*             By defining an array as the third parameter you are able to define some statements to be executed. The key
*             is defining what to execute, numbers can be appended...
*
*             'function' => execute function
*             'execute' => run code, whereby all occurrences of {VALUE} get replaced by the last returned value.
*                   The result *must* be assigned/stored to {RESULT}.
*             'typecast'  => typecast value
*
*             The returned variables will be made always available to the next function to continue to work with.
*
*             example (variable inputted is an integer of 1):
*
*             array(
*               'function1'   => 'increment_by_one',    // returned variable is 2
*               'typecast'    => 'string',        // typecast variable to be a string
*               'execute'   => '{RESULT} = {VALUE} . ' is good';', // returned variable is '2 is good'
*               'function2'   => 'replace_good_with_bad',       // returned variable is '2 is bad'
*             ),
*
*/
  $convertor = array(
    'test_file'       => $test_file,

    'execute_first' => 'insert_forums(); insert_bbcodes();',

    'execute_last'  => array(
      'add_bots();',
      'activate_topic_polls();',
      'grant_permissions();',
      'grant_forum_permissions();',
      'grant_category_permissions();',
      'update_folder_pm_count();',
      'update_unread_count();',
      'update_last_post_info();',
    ),

    'schema' => array(
      array(
        'target'      => USERS_TABLE,
        'primary'   => 'users.id',
        'autoincrement' => 'user_id',
        'query_first' => array(
          array('target', 'DELETE FROM ' . USERS_TABLE . ' WHERE user_id > ' . 2), // Leave anon and admin as is // <> ' . ANONYMOUS
          array('target', $convert->truncate_statement . BOTS_TABLE),
        ),

        array('user_id',  'users.id',            ''),
        array('username',  'users.username',            ''),
        array('username_clean',  'users.username',            'utf8_clean_string'),
        array('user_email',  'users.email',            ''),
        array('user_password',   'users.password',                 ''),
        array('user_style',       $config['default_style'],   ''),
        array('user_permissions',   '',                 ''),
        array('user_sig',   'kunena_users.signature',                 ''),
        array('user_regdate',   'users.registerDate',                 ''),
        array('user_lastvisit',   'users.lastvisitDate',              ''),


        'left_join' => 'users LEFT JOIN kunena_users ON users.id = kunena_users.userid',
        'where' => "users.username <> 'Anonymous'" // TODO Remove
      ),

      array(
        'target'        => TOPICS_TABLE,
        'query_first'   => array('target', $convert->truncate_statement . TOPICS_TABLE),
        'primary'       => 'kunena_topics.id',
        'autoincrement' => 'topic_id',

        array('topic_id',       'kunena_topics.id',      ''),
        array('forum_id',       'kunena_topics.category_id',      ''),
        array('topic_title',    'kunena_topics.subject',      ''),
        array('topic_poster',   'kunena_topics.first_post_userid',      ''),
        array('topic_time',     'kunena_topics.first_post_time',      ''),
        array('topic_views',    'kunena_topics.hits',      ''),
        array('topic_visibility',  1,        ''),

        array('topic_first_post_id',     'kunena_topics.first_post_id',      ''),
        array('topic_first_poster_name', 'kunena_topics.first_post_guest_name',      ''),

        array('topic_last_post_id',     'kunena_topics.last_post_id',      ''),
        array('topic_last_post_time',   'kunena_topics.last_post_time',      ''),
        array('topic_last_poster_id',   'kunena_topics.last_post_userid',      ''),
        array('topic_last_poster_name', 'kunena_topics.last_post_guest_name',      ''),

        array('poll_title',       'kunena_polls.title',   ''),
        array('poll_max_options',   1,              ''),
        array('poll_vote_change',   0,              ''),

        'left_join' => 'kunena_topics LEFT JOIN kunena_polls ON kunena_polls.threadid = kunena_topics.id',
      ),

      array(
        'target'        => POSTS_TABLE,
        'query_first'   => array('target', $convert->truncate_statement . POSTS_TABLE),
        'primary'       => 'kunena_messages.id',
        'autoincrement' => 'post_id',

        array('post_id',         'kunena_messages.id',      ''),
        array('topic_id',        'kunena_messages.thread',      ''),
        array('forum_id',        'kunena_messages.catid',      ''),
        array('poster_id',       'kunena_messages.userid',      ''),
        array('poster_ip',       'kunena_messages.ip',      ''),
        array('post_time',       'kunena_messages.time',      ''),
        array('post_subject',    'kunena_messages.subject',      ''),
        array('post_text',       'kunena_messages_text.message', 'prepare_message'),
        array('bbcode_uid',      '',                             'get_bbcode_uid'),
        array('bbcode_bitfield', '',                             'get_bbcode_bitfield'),
        array('post_visibility', 1,      ''),
        array('post_checksum',   '',               ''),

        array('post_edit_count',    'kunena_messages.modified_time',    'is_positive'),
        array('post_edit_time',     'kunena_messages.modified_time',    array('typecast' => 'int')),
        array('post_edit_reason',   'kunena_messages.modified_reason',               ''),
        array('post_edit_user',     'kunena_messages.modified_by',    ''),

        'left_join' => 'kunena_messages LEFT JOIN kunena_messages_text ON kunena_messages.id = kunena_messages_text.mesid',
      ),

      array(
        'target'    => POLL_OPTIONS_TABLE,
        'primary'   => 'kunena_polls_options.id',
        'query_first' => array('target', $convert->truncate_statement . POLL_OPTIONS_TABLE),

        array('poll_option_id',     'kunena_polls_options.id',    ''),
        array('topic_id',           'kunena_polls.threadid',     ''),
        array('poll_option_text',   'kunena_polls_options.text',  ''),
        array('poll_option_total',  'kunena_polls_options.votes', ''),

        'left_join' => 'kunena_polls_options LEFT JOIN kunena_polls ON kunena_polls_options.pollid = kunena_polls.id',
      ),

      array(
        'target'    => POLL_VOTES_TABLE,
        'query_first' => array('target', $convert->truncate_statement . POLL_VOTES_TABLE),

        array('topic_id',       'kunena_polls.threadid',       ''),
        array('poll_option_id', 'kunena_polls_users.lastvote', ''),
        array('vote_user_id',   'kunena_polls_users.userid',   ''),

        'order_by'    => 'kunena_polls_users.lasttime ASC',
        'left_join' => 'kunena_polls_users LEFT JOIN kunena_polls ON kunena_polls_users.pollid = kunena_polls.id',
      ),
    ),
  );
}
?>
