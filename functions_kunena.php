<?php

function truncate_table($table)
{
  global $db, $convert;
  $db->sql_query($convert->truncate_statement . $table);
}

/**
* Calculate the left right id's for forums. This is a recursive function.
*/
function left_right_ids($groups, $parent_id, &$forums, &$node)
{
  foreach ($groups[$parent_id] as $forum_id)
  {
    $forums[$forum_id]['left_id'] = $node++;

    if (!empty($groups[$forum_id]))
    {
      left_right_ids($groups, $forum_id, $forums, $node);
    }

    $forums[$forum_id]['right_id'] = $node++;
  }
}

/**
* Insert/Convert forums
*/
function insert_forums()
{
  global $db, $src_db, $convert, $config;

  truncate_table(FORUMS_TABLE);

  // Loading forum data

  $sql = 'SELECT id, parent_id, name, description  FROM ' . $convert->src_table_prefix . 'kunena_categories ORDER BY id';
  $result = $src_db->sql_query($sql);

  $forums = $forum_groups = $last_topics = array();

  while ($row = $src_db->sql_fetchrow($result))
  {
    $forums[$row['id']] = $row;
    $forum_groups[$row['parent_id']][] = $row['id'];
  }

  $src_db->sql_freeresult($result);

  $node = 1;
  left_right_ids($forum_groups, 0, $forums, $node);

  foreach ($forums as $forum_id => $row)
  {
    // Define the new forums sql ary
    $sql_ary = array(
      'forum_id'      => (int) $row['id'],
      'forum_name'    => $row['name'],
      'parent_id'     => (int) $row['parent_id'],
      'forum_parents' => '',
      'forum_desc'    => $row['description'],
      'forum_type'    => ($row['parent_id']) ? FORUM_POST : FORUM_CAT,
      'forum_status'  => ITEM_UNLOCKED,
      'left_id'       => $row['left_id'],
      'right_id'      => $row['right_id'],
      'enable_icons'  => 1,

      // Default values
      'forum_desc_bitfield'   => '',
      'forum_desc_options'    => 7,
      'forum_desc_uid'      => '',
      'forum_style'       => 0,
      'forum_image'       => '',
      'forum_rules'       => '',
      'forum_rules_link'      => '',
      'forum_rules_bitfield'    => '',
      'forum_rules_options'   => 7,
      'forum_rules_uid'     => '',
      'forum_topics_per_page'   => 0,
      'forum_posts_approved'    => 0,
      'forum_posts_unapproved'  => 0,
      'forum_posts_softdeleted' => 0,
      'forum_topics_approved'   => 0,
      'forum_topics_unapproved' => 0,
      'forum_topics_softdeleted'  => 0,
      'display_on_index'      => 1,
      'enable_indexing'     => 1,
    );

    $sql = 'INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary);
    $db->sql_query($sql);
  }
}

function grant_permissions()
{
  global $db, $phpbb_root_path, $phpEx;

  truncate_table(ACL_USERS_TABLE);
  truncate_table(ACL_GROUPS_TABLE);
/*
  // Grab users with admin permissions
  $sql = "SELECT uid, permissions
    FROM {$this->src_table_prefix}adminoptions
    WHERE uid >= 1";
  $result = $this->src_db->sql_query($sql);*/

  $admins = $founders = array();

/*
  while ($row = $this->src_db->sql_fetchrow($result))
  {
    $user_id = (int) $this->get_user_id($row['uid']);
    $permissions = unserialize($row['permissions']);
    $admins[] = $user_id;

    if ($permissions['user']['admin_permissions'])
    {
      $founders[] = $user_id;
    }
  }
  $this->src_db->sql_freeresult($result);

  // We'll set the users that can manage admin permissions as founders.
  $sql = 'UPDATE ' . USERS_TABLE . '
    SET user_type = ' . USER_FOUNDER . "
    WHERE " . $this->db->sql_in_set('user_id', $founders);
  $this->db->sql_query($sql);*/

  $bot_group_id = get_group_id('bots');

  // Add the anonymous user to the GUESTS group, and everyone else to the REGISTERED group
  user_group_auth('guests', 'SELECT user_id, {GUESTS} FROM ' . USERS_TABLE . ' WHERE user_id = ' . ANONYMOUS, false);
  user_group_auth('registered', 'SELECT user_id, {REGISTERED} FROM ' . USERS_TABLE . ' WHERE user_id <> ' . ANONYMOUS . " AND group_id <> $bot_group_id", false);

  if (!function_exists('group_set_user_default'))
  {
    include($phpbb_root_path . 'includes/functions_user.' . $phpEx);
  }

  if ($admins)
  {
    $auth_sql = 'SELECT user_id, {ADMINISTRATORS} FROM ' . USERS_TABLE . ' WHERE ' . $db->sql_in_set('user_id', $admins);
    user_group_auth('administrators', $auth_sql, false);

    $auth_sql = 'SELECT user_id, {GLOBAL_MODERATORS} FROM ' . USERS_TABLE . ' WHERE ' . $db->sql_in_set('user_id', $admins);
    user_group_auth('global_moderators', $auth_sql, false);

    // Set the admin group as their default group.
    group_set_user_default(get_group_id('administrators'), $admins);
  }

  // Assign permission roles and other default permissions

  // guests having u_download and u_search ability
  $db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . ' (group_id, forum_id, auth_option_id, auth_role_id, auth_setting) SELECT ' . get_group_id('guests') . ', 0, auth_option_id, 0, 1 FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option IN ('u_', 'u_download', 'u_search')");

  // administrators/global mods having full user features
  mass_auth('group_role', 0, 'administrators', 'USER_FULL');
  mass_auth('group_role', 0, 'global_moderators', 'USER_FULL');

  // By default all converted administrators are given full access
  mass_auth('group_role', 0, 'administrators', 'ADMIN_FULL');

  // All registered users are assigned the standard user role
  mass_auth('group_role', 0, 'registered', 'USER_STANDARD');
  mass_auth('group_role', 0, 'registered_coppa', 'USER_STANDARD');

  // Instead of administrators being global moderators we give the MOD_FULL role to global mods (admins already assigned to this group)
  mass_auth('group_role', 0, 'global_moderators', 'MOD_FULL');
}

function grant_category_permissions()
{
  global $db, $auth;

  $sql = 'SELECT forum_id, forum_name, parent_id, left_id, right_id
    FROM ' . FORUMS_TABLE . '
    ORDER BY left_id ASC';
  $result = $db->sql_query($sql);

  $categories = array();
  $forums = array();
  while ($row = $db->sql_fetchrow($result))
  {
    if ($row['parent_id'] == 0)
    {
      mass_auth('group_role', $row['forum_id'], 'administrators', 'FORUM_FULL');
      mass_auth('group_role', $row['forum_id'], 'global_moderators', 'FORUM_FULL');
      $categories[] = $row;
    }
    else
    {
      $forums[] = $row;
    }
  }
  $db->sql_freeresult($result);

  foreach ($categories as $row)
  {
    // Get the children
    $branch = $forum_ids = array();

    foreach ($forums as $key => $_row) {
      if ($_row['left_id'] > $row['left_id'] && $_row['left_id'] < $row['right_id']) {
        $branch[] = $_row;
        $forum_ids[] = $_row['forum_id'];
        continue;
      }
    }

    if (sizeof($forum_ids)) {
      // Now make sure the user is able to read these forums
      $hold_ary = $auth->acl_group_raw_data(false, 'f_list', $forum_ids);

      if (empty($hold_ary)) {
        continue;
      }

      foreach ($hold_ary as $g_id => $f_id_ary) {
        $set_group = false;

        foreach ($f_id_ary as $f_id => $auth_ary) {
          foreach ($auth_ary as $auth_option => $setting) {
            if ($setting == ACL_YES)
            {
              $set_group = true;
              break 2;
            }
          }
        }

        if ($set_group) {
          mass_auth('group', $row['forum_id'], $g_id, 'f_list', ACL_YES);
        }
      }
    }
  }
}

function grant_forum_permissions()
{
  global $db, $auth;

  $sql = 'SELECT forum_id, forum_name, parent_id, left_id, right_id
    FROM ' . FORUMS_TABLE . '
    WHERE parent_id <> 0';
  $result = $db->sql_query($sql);

  $forums = array();
  while ($row = $db->sql_fetchrow($result))
  {
    mass_auth('group_role', $row['forum_id'], 'administrators', 'FORUM_FULL');
    mass_auth('group_role', $row['forum_id'], 'administrators', 'MOD_FULL');
    mass_auth('group_role', $row['forum_id'], 'global_moderators', 'FORUM_FULL');
    mass_auth('group_role', $row['forum_id'], 'global_moderators', 'MOD_FULL');
    $forums[] = $row;
  }
  $db->sql_freeresult($result);

  foreach ($forums as $row) {
    mass_auth('group_role', $row['forum_id'], 'guests', 'FORUM_READONLY');
    mass_auth('group_role', $row['forum_id'], 'registered', 'FORUM_POLLS');
    mass_auth('group_role', $row['forum_id'], 'registered_coppa', 'FORUM_STANDARD');
    mass_auth('group_role', $row['forum_id'], 'bots', 'FORUM_BOT');
    mass_auth('group_role', $row['forum_id'], 'newly_registered', 'FORUM_NEW_MEMBER');
  }
}

function update_last_post_info()
{
  global $db;

  $sql = 'SELECT forum_id, forum_name, parent_id, left_id, right_id
    FROM ' . FORUMS_TABLE . '
    WHERE parent_id <> 0';
  $result = $db->sql_query($sql);

  $forums = array();
  while ($row = $db->sql_fetchrow($result))
  {
    $forums[] = $row;
  }
  $db->sql_freeresult($result);

  foreach ($forums as $frow) {
    $sql = 'SELECT topic_last_post_id, topic_last_poster_id
      FROM ' . TOPICS_TABLE . ' WHERE forum_id = ' . $frow['forum_id'] . '
      ORDER BY topic_last_post_id DESC LIMIT 1';
    $result = $db->sql_query($sql);
    while ($trow = $db->sql_fetchrow($result))
    {
      $sql = 'UPDATE ' . FORUMS_TABLE . ' SET forum_last_post_id = ' . $trow['topic_last_post_id'] . ', forum_last_poster_id = ' . $trow['topic_last_poster_id'] . '
        WHERE forum_id = ' . $frow['forum_id'];
      $db->sql_query($sql);
    }
    $db->sql_freeresult($result);
  }
}

function prepare_message($text) {
  global $convert;

  $bbcode_conversions = array(
    '[ol]' => '[list=1]',
    '[/ol]' => '[/list]',
    '[ul]' => '[list]',
    '[/ul]' => '[/list]',
    '[li]' => '[*]',
    '[/li]' => '',
  );

  // Convert bbcodes
  $text = str_replace(array_keys($bbcode_conversions), array_values($bbcode_conversions), $text);

  // Remove spaces inside bbcodes
  $text = preg_replace('/\s+\]/', ']', $text);

  $uid = $bitfield = $options = ''; // will be modified by generate_text_for_storage
  $allow_bbcode = $allow_urls = $allow_smilies = true;

  generate_text_for_storage($text, $uid, $bitfield, $options, $allow_bbcode, $allow_urls, $allow_smilies);

  $convert->row['bbcode_uid'] = $uid;
  $convert->row['bbcode_bitfield'] = $bitfield;
  $convert->row['bbcode_options'] = $options;

  return $text;
}

function get_bbcode_uid() {
  global $convert;
  return $convert->row['bbcode_uid'];
}

function get_bbcode_bitfield() {
  global $convert;
  return $convert->row['bbcode_bitfield'];
}

function activate_topic_polls() {
  global $db, $convert;

  $db->sql_query('UPDATE ' . TOPICS_TABLE . " SET poll_start = topic_time WHERE poll_title <> ''");
}

function insert_bbcodes() {
  global $phpbb_root_path, $phpEx, $db;

  $bbcode_templates = array(
    '[align={SIMPLETEXT}]{TEXT}[/align]'  => '<div style="text-align: {SIMPLETEXT};">{TEXT}</div>',
    '[font={SIMPLETEXT}]{TEXT}[/font]'    => '<span style="font-family: {SIMPLETEXT};">{TEXT}</span>',
    '[hr][/hr]'                           => '<hr />',
    '[s]{TEXT}[/s]'                       => '<span style="text-decoration: line-through;">{TEXT}</span>',
  );

  if (!class_exists('acp_bbcodes')) {
    include($phpbb_root_path . 'includes/acp/acp_bbcodes.' . $phpEx);
  }

  $bbcode = new \acp_bbcodes();
  $bbcode_settings = array();

  $bbcode_id = NUM_CORE_BBCODES;

  // Build bbcode regexps
  foreach ($bbcode_templates as $match => $tpl) {
    $settings = $bbcode->build_regexp($match, $tpl);

    $bbcode_settings[$settings['bbcode_tag']] = array_merge($settings, array(
      'bbcode_match'        => $match,
      'bbcode_tpl'          => $tpl,
      'display_on_posting'  => 1,
      'bbcode_helpline'     => '',
      'bbcode_id'           => ++$bbcode_id,
    ));
  }

  truncate_table(BBCODES_TABLE);

  $db->sql_multi_insert(BBCODES_TABLE, array_values($bbcode_settings));
}

?>
