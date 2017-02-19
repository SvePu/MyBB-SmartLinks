<?php

/*
MyBB SmartLinks Plugin for MyBB 1.8
Copyright (C) 2017 SvePu

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if(defined('IN_ADMINCP'))
{
	$plugins->add_hook('admin_config_action_handler','mybbsmartlinks_admin_action');
	$plugins->add_hook('admin_config_menu','mybbsmartlinks_admin_menu');
	$plugins->add_hook('admin_config_permissions','mybbsmartlinks_admin_permissions');
	$plugins->add_hook('admin_load','mybbsmartlinks_admin');
}
else
{
	$plugins->add_hook("parse_message", "mybbsmartlinks_parse_message");
}

function mybbsmartlinks_info()
{
	global $db, $lang;
	$lang->load("config_mybbsmartlinks");
	
	return array(
		"name"		=>	$db->escape_string($lang->mybbsmartlinks_info_name),
		"description"	=>	$db->escape_string($lang->mybbsmartlinks_info_description),
		"website"	=>	"https://github.com/SvePu/MyBB-SmartLinks",
		"author"	=>	"SvePu",
		"authorsite"	=>	"https://github.com/SvePu",
		"version"	=>	"1.0",
		"codename"	=>	"mybbsmartlinks",
		"compatibility"	=>	"18*"
		);
}

function mybbsmartlinks_activate()
{
	change_admin_permission('tools','mybbsmartlinks');
	mybbsmartlinks_cache();
}

function mybbsmartlinks_deactivate()
{
	change_admin_permission('tools','mybbsmartlinks',-1);
	mybbsmartlinks_cache(true);
}

function mybbsmartlinks_install()
{
	global $db;	
	if($db->engine == 'mysql' || $db->engine == 'mysqli')
	{	
		$db->query("CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX."smartlinks` (
			  `slid` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `word` varchar(100) NOT NULL DEFAULT '',
			  `url` varchar(200) NOT NULL DEFAULT '',
			  PRIMARY KEY (`slid`),
			  UNIQUE KEY `slid` (`slid`)
			) ENGINE=MyISAM".$db->build_create_table_collation());
	}
}

function mybbsmartlinks_is_installed()
{
	global $mybb, $db;
	$smcache = $db->simple_select('datacache', '*', 'title="smartlinks"');
	if($db->num_rows($smcache) > 0 && $db->table_exists('smartlinks'))
	{
		$fields = $db->show_fields_from('smartlinks');
		$list = array();
		$check = array
		(
			'slid',
			'word',
			'url'
			);
		foreach($fields as $key => $val)
		{
			array_push($list,$val['Field']);
		}
		$diff = array_diff($check,$list);
		if(empty($diff))
		{
			return true;
		}
	}
	return false;
}

function mybbsmartlinks_uninstall()
{
	global $mybb, $db;
	if($mybb->request_method != 'post')
	{
		global $page, $lang;
		$lang->load('config_mybbsmartlinks');
		$page->output_confirm_action('index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=mybbsmartlinks', $lang->mybbsmartlinks_uninstall_message, $lang->mybbsmartlinks_uninstall);
	}
	
	if(!isset($mybb->input['no']) && $db->table_exists('smartlinks'))
	{
		$db->drop_table('smartlinks');
	}
}

function mybbsmartlinks_admin_action($action)
{
	$action['mybbsmartlinks'] = array
	(
		'active'=>'mybbsmartlinks'
		);
	return $action;
}

function mybbsmartlinks_admin_menu($sub_menu)
{
	global $lang;
	$lang->load("config_mybbsmartlinks");
	end($sub_menu);
	$key=(key($sub_menu))+10;
	$sub_menu[$key] = array
	(
		'id'	=>	'mybbsmartlinks',
		'title'	=>	$lang->mybbsmartlinks_info_name,
		'link'	=>	'index.php?module=config-mybbsmartlinks'
		);
	return $sub_menu;
}

function mybbsmartlinks_admin_permissions($admin_permissions)
{
	global $lang;
	$lang->load("config_mybbsmartlinks");
	$admin_permissions['mybbsmartlinks'] = $lang->mybbsmartlinks_can_manage_smartlinks;
	return $admin_permissions;
}

function mybbsmartlinks_admin()
{
	global $mybb, $page, $db, $lang;
	$lang->load("config_mybbsmartlinks");
	if($page->active_action != 'mybbsmartlinks')
	{
		return false;
	}
	$info = mybbsmartlinks_info();
	
	$page->add_breadcrumb_item($lang->mybbsmartlinks_info_name, "index.php?module=config-mybbsmartlinks");

	if($mybb->input['action'] == "add" && $mybb->request_method == "post")
	{

		if(!trim($mybb->input['word']))
		{
			$errors[] = $lang->error_missing_smartlink;
		}

		if(strlen(trim($mybb->input['word'])) > 100)
		{
			$errors[] = $lang->smartlink_max;
		}

		if(!preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $mybb->input['url']))
		{
			$errors[] = $lang->smartlink_url_invalid;
		}
		
		if(strlen($mybb->input['url']) > 200)
		{
			$errors[] = $lang->smartlink_url_word_max;
		}

		if(!$errors)
		{
			$query = $db->simple_select("smartlinks", "slid", "word = '".$db->escape_string($mybb->input['word'])."'");

			if($db->num_rows($query))
			{
				$errors[] = $lang->error_smartlink_filtered;
			}
		}

		$word = str_replace('\*', '([a-zA-Z0-9_]{1})', preg_quote($mybb->input['word'], "#"));

		if(strlen($mybb->input['word']) == strlen($mybb->input['url']) && preg_match("#(^|\W)".$word."(\W|$)#i", $mybb->input['url']))
		{
			$errors[] = $lang->error_smartlink_url_word_invalid;
		}

		if(!$errors)
		{
			$new_smartlink = array(
				"word" => $db->escape_string($mybb->input['word']),
				"url" => $db->escape_string($mybb->input['url'])
			);

			$slid = $db->insert_query("smartlinks", $new_smartlink);

			// Log admin action
			log_admin_action($slid, $mybb->input['word'], $mybb->input['url']);

			mybbsmartlinks_cache();
			flash_message($lang->success_added_smartlink, 'success');
			admin_redirect("index.php?module=config-mybbsmartlinks");
		}
		else
		{
			$mybb->input['action'] = '';
		}
	}

	if($mybb->input['action'] == "delete")
	{
		$query = $db->simple_select("smartlinks", "*", "slid='".$mybb->get_input('slid', MyBB::INPUT_INT)."'");
		$smartlink = $db->fetch_array($query);

		// Does the bad word not exist?
		if(!$smartlink['slid'])
		{
			flash_message($lang->error_invalid_slid, 'error');
			admin_redirect("index.php?module=config-mybbsmartlinks");
		}

		if($mybb->input['no'])
		{
			admin_redirect("index.php?module=config-mybbsmartlinks");
		}

		if($mybb->request_method == "post")
		{
			$db->delete_query("smartlinks", "slid='{$smartlink['slid']}'");

			log_admin_action($smartlink['slid'], $smartlink['word'], $smartlink['url']);

			mybbsmartlinks_cache();

			flash_message($lang->success_deleted_smartlink, 'success');
			admin_redirect("index.php?module=config-mybbsmartlinks");
		}
		else
		{
			$page->output_confirm_action("index.php?module=config-mybbsmartlinks&action=delete&slid={$smartlink['slid']}", $lang->confirm_smartlink_deletion);
		}
	}

	if($mybb->input['action'] == "edit")
	{
		$query = $db->simple_select("smartlinks", "*", "slid='".$mybb->get_input('slid', MyBB::INPUT_INT)."'");
		$smartlink = $db->fetch_array($query);

		if(!$smartlink['slid'])
		{
			flash_message($lang->error_invalid_slid, 'error');
			admin_redirect("index.php?module=config-mybbsmartlinks");
		}

		if($mybb->request_method == "post")
		{
			if(!trim($mybb->input['word']))
			{
				$errors[] = $lang->error_missing_smartlink;
			}

			if(strlen(trim($mybb->input['word'])) > 100)
			{
				$errors[] = $lang->smartlink_max;
			}
			
			if(!preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $mybb->input['url']))
			{
				$errors[] = $lang->smartlink_url_invalid;
			}

			if(strlen($mybb->input['url']) > 200)
			{
				$errors[] = $lang->smartlink_url_word_max;
			}

			if(!$errors)
			{
				$updated_smartlink = array(
					"word" => $db->escape_string($mybb->input['word']),
					"url" => $db->escape_string($mybb->input['url'])
				);

				$db->update_query("smartlinks", $updated_smartlink, "slid='{$smartlink['slid']}'");

				log_admin_action($smartlink['slid'], $mybb->input['word'], $mybb->input['url']);

				mybbsmartlinks_cache();

				flash_message($lang->success_updated_smartlink, 'success');
				admin_redirect("index.php?module=config-mybbsmartlinks");
			}
		}

		$page->add_breadcrumb_item($lang->edit_smartlink);
		$page->output_header($lang->smartlinks." - ".$lang->edit_smartlink);

		$sub_tabs['editsmartlink'] = array(
			'title' => $lang->edit_smartlink,
			'description' => $lang->edit_smartlink_desc,
			'link' => "index.php?module=config-mybbsmartlinks"
		);

		$page->output_nav_tabs($sub_tabs, "editsmartlink");

		$form = new Form("index.php?module=config-mybbsmartlinks&amp;action=edit&amp;slid={$smartlink['slid']}", "post");

		if($errors)
		{
			$page->output_inline_error($errors);
			$smartlink_data = $mybb->input;
		}
		else
		{
			$smartlink_data = $smartlink;
		}

		$form_container = new FormContainer($lang->edit_smartlink);
		$form_container->output_row($lang->smartlink." <em>*</em>", $lang->smartlink_desc, $form->generate_text_box('word', $smartlink_data['word'], array('id' => 'word')), 'word');
		$form_container->output_row($lang->smartlink_url, $lang->smartlink_url_desc, $form->generate_text_box('url', $smartlink_data['url'], array('id' => 'url')), 'url');
		$form_container->end();
		$buttons[] = $form->generate_submit_button($lang->save_smartlink);
		$form->output_submit_wrapper($buttons);
		$form->end();

		$page->output_footer();
	}

	if(!$mybb->input['action'])
	{
		$page->output_header($lang->smartlinks);

		$sub_tabs['smartlinks'] = array(
			'title' => $lang->smartlink_filters,
			'description' => $lang->smartlink_filters_desc,
			'link' => "index.php?module=config-mybbsmartlinks"
		);

		$page->output_nav_tabs($sub_tabs, "smartlinks");

		if($errors)
		{
			$page->output_inline_error($errors);
		}

		$table = new Table;
		$table->construct_header($lang->smartlink);
		$table->construct_header($lang->smartlink_url, array("width" => "50%"));
		$table->construct_header($lang->controls, array("class" => "align_center", "width" => 150, "colspan" => 2));

		$query = $db->simple_select("smartlinks", "*", "", array("order_by" => "word", "order_dir" => "asc"));
		while($smartlink = $db->fetch_array($query))
		{
			$smartlink['word'] = htmlspecialchars_uni($smartlink['word']);
			$smartlink['url'] = htmlspecialchars_uni($smartlink['url']);
			
			$table->construct_cell($smartlink['word']);
			$table->construct_cell($smartlink['url']);
			$table->construct_cell("<a href=\"index.php?module=config-mybbsmartlinks&amp;action=edit&amp;slid={$smartlink['slid']}\">{$lang->edit}</a>", array("class" => "align_center"));
			$table->construct_cell("<a href=\"index.php?module=config-mybbsmartlinks&amp;action=delete&amp;slid={$smartlink['slid']}&amp;my_post_key={$mybb->post_code}\" onclick=\"return AdminCP.deleteConfirmation(this, '{$lang->confirm_smartlink_deletion}');\">{$lang->delete}</a>", array("class" => "align_center"));
			$table->construct_row();
		}

		if($table->num_rows() == 0)
		{
			$table->construct_cell($lang->no_smartlinks, array("colspan" => 4));
			$table->construct_row();
		}

		$table->output($lang->smartlink_filters);

		$form = new Form("index.php?module=config-mybbsmartlinks&amp;action=add", "post", "add");

		$form_container = new FormContainer($lang->add_smartlink);
		$form_container->output_row($lang->smartlink." <em>*</em>", $lang->smartlink_desc, $form->generate_text_box('word', $mybb->input['word'], array('id' => 'word')), 'word');
		$form_container->output_row($lang->smartlink_url, $lang->smartlink_url_desc, $form->generate_text_box('url', $mybb->input['url'], array('id' => 'url')), 'url');
		$form_container->end();
		$buttons[] = $form->generate_submit_button($lang->save_smartlink);
		$form->output_submit_wrapper($buttons);
		$form->end();

		$page->output_footer();
	}
}

function mybbsmartlinks_parse_message($message)
{
	global $mybb, $cache;
	$slcache = $cache->read('smartlinks');
	
	if(is_array($slcache))
	{
		reset($slcache);
		foreach($slcache as $slid => $smartlink)
		{		
			if(!$smartlink['url'])
			{
				$smartlink['url'] = $mybb->settings['bburl'];
			}
			$smartlink['word'] = str_replace('\*', '([a-zA-Z0-9_]{1})', preg_quote($smartlink['word'], "#"));
			
			$message = preg_replace("#(^|\W)".$smartlink['word']."(?=\W|$)#i", '\1<a href="'.$smartlink['url'].'" target="_blank">'.trim($smartlink['word']).'</a>', $message);
		}
	}
	return $message;
}

function mybbsmartlinks_cache($clear=false)
{
	global $cache;
	if($clear==true)
	{
		global $db;
		$db->delete_query('datacache', 'title="smartlinks"');
	}
	else
	{
		global $db;
		$smartlinks = array();
		$query = $db->simple_select('smartlinks','slid,word,url');
		while($smartlink = $db->fetch_array($query))
		{
			$smartlinks[$smartlink['slid']] = $smartlink;
		}
		$cache->update('smartlinks',$smartlinks);
	}
}
