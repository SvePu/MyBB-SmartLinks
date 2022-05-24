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

if (!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (defined('IN_ADMINCP'))
{
    $plugins->add_hook('admin_config_action_handler', 'mybbsmartlinks_admin_action');
    $plugins->add_hook('admin_config_menu', 'mybbsmartlinks_admin_menu');
    $plugins->add_hook('admin_config_permissions', 'mybbsmartlinks_admin_permissions');
    $plugins->add_hook('admin_load', 'mybbsmartlinks_admin');
    $plugins->add_hook("admin_tools_cache_start", "mybbsmartlinks_tools_cache_rebuild");
    $plugins->add_hook("admin_tools_cache_rebuild", "mybbsmartlinks_tools_cache_rebuild");
}
else
{
    $plugins->add_hook("parse_message_end", "mybbsmartlinks_parse_message");
}

function mybbsmartlinks_info()
{
    global $db, $lang;
    $lang->load("config_mybbsmartlinks");

    return array(
        "name"          => $db->escape_string($lang->mybbsmartlinks_info_name),
        "description"   => $db->escape_string($lang->mybbsmartlinks_info_description),
        "website"       => "https://github.com/SvePu/MyBB-SmartLinks",
        "author"        => "SvePu",
        "authorsite"    => "https://github.com/SvePu",
        "version"       => "1.4",
        "codename"      => "mybbsmartlinks",
        "compatibility" => "18*"
    );
}

function mybbsmartlinks_activate()
{
    change_admin_permission('config', 'mybbsmartlinks');
    mybbsmartlinks_cache();
}

function mybbsmartlinks_deactivate()
{
    change_admin_permission('config', 'mybbsmartlinks', -1);
    mybbsmartlinks_cache(true);
}

function mybbsmartlinks_install()
{
    global $db;

    /** Install DB Table */
    $collation = $db->build_create_table_collation();

    if (!$db->table_exists('smartlinks'))
    {
        switch ($db->type)
        {
            case "pgsql":
                $db->write_query("CREATE TABLE " . TABLE_PREFIX . "smartlinks (
                    slid serial,
                    word varchar(100) NOT NULL default '',
                    url varchar(200) NOT NULL default '',
                    urltitle varchar(100) NOT NULL default '',
                    nofollow smallint NOT NULL default '0',
                    newtab smallint NOT NULL default '0',
                    PRIMARY KEY (slid)
                );");
                break;
            case "sqlite":
                $db->write_query("CREATE TABLE " . TABLE_PREFIX . "smartlinks (
                    slid INTEGER PRIMARY KEY,
                    word varchar(100) NOT NULL default '',
                    url varchar(200) NOT NULL default '',
                    urltitle varchar(100) NOT NULL default '',
                    nofollow tinyint(1) NOT NULL default '0',
                    newtab tinyint(1) NOT NULL default '0'
                );");
                break;
            default:
                $db->write_query("CREATE TABLE " . TABLE_PREFIX . "smartlinks (
                    slid int(10) unsigned NOT NULL AUTO_INCREMENT,
                    word varchar(100) NOT NULL DEFAULT '',
                    url varchar(200) NOT NULL DEFAULT '',
                    urltitle varchar(100) NOT NULL DEFAULT '',
                    nofollow tinyint(1) NOT NULL DEFAULT '0',
                    newtab tinyint(1) NOT NULL DEFAULT '0',
                    PRIMARY KEY (slid),
                    UNIQUE KEY slid (slid)
                ) ENGINE=MyISAM{$collation};");
                break;
        }
    }
}

function mybbsmartlinks_is_installed()
{
    global $db, $cache;
    if ($cache->size_of('smartlinks') > 0 && $db->table_exists('smartlinks'))
    {
        return true;
    }
    return false;
}

function mybbsmartlinks_uninstall()
{
    global $mybb, $db;
    if ($mybb->request_method != 'post')
    {
        global $page, $lang;
        $lang->load('config_mybbsmartlinks');
        $page->output_confirm_action('index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=mybbsmartlinks', $lang->mybbsmartlinks_uninstall_message, $lang->mybbsmartlinks_uninstall);
    }

    if (!isset($mybb->input['no']) && $db->table_exists('smartlinks'))
    {
        $db->drop_table('smartlinks');
    }

    mybbsmartlinks_cache(true, true);
}

function mybbsmartlinks_admin_action(&$action)
{
    $action['mybbsmartlinks'] = array(
        'active' => 'mybbsmartlinks'
    );
}

function mybbsmartlinks_admin_menu(&$sub_menu)
{
    global $lang;
    $lang->load("config_mybbsmartlinks");
    end($sub_menu);
    $key = (key($sub_menu)) + 10;
    $sub_menu[$key] = array(
        'id'    =>    'mybbsmartlinks',
        'title'    =>    $lang->mybbsmartlinks_info_name,
        'link'    =>    'index.php?module=config-mybbsmartlinks'
    );
}

function mybbsmartlinks_admin_permissions(&$admin_permissions)
{
    global $lang;
    $lang->load("config_mybbsmartlinks");
    $admin_permissions['mybbsmartlinks'] = $lang->mybbsmartlinks_can_manage_smartlinks;
}

function mybbsmartlinks_admin()
{
    global $mybb, $page, $db, $lang;
    $lang->load("config_mybbsmartlinks");
    if ($page->active_action != 'mybbsmartlinks')
    {
        return false;
    }
    $info = mybbsmartlinks_info();

    $page->add_breadcrumb_item($lang->mybbsmartlinks_info_name, "index.php?module=config-mybbsmartlinks");

    if ($mybb->input['action'] == "add")
    {

        if ($mybb->request_method == "post")
        {
            $mybb->input['word'] = trim($mybb->input['word']);
            $mybb->input['url'] = trim($mybb->input['url']);
            $mybb->input['urltitle'] = trim($mybb->input['urltitle']);

            if (!$mybb->input['word'])
            {
                $errors[] = $lang->error_missing_smartlink;
            }

            if (preg_match('/\b(mybb)\b/i', $mybb->input['word']))
            {
                $errors[] = $lang->sprintf($lang->smartlink_word_invalid, $mybb->input['word']);
            }

            if (strlen($mybb->input['word']) > 100)
            {
                $errors[] = $lang->smartlink_max;
            }

            if (!preg_match('/^(http:\/\/www\.|https:\/\/www\.|http:\/\/|https:\/\/|www\.)/i', $mybb->input['url']))
            {
                $errors[] = $lang->smartlink_url_invalid;
            }

            if (strlen($mybb->input['url']) > 200)
            {
                $errors[] = $lang->smartlink_url_word_max;
            }

            if (!$errors)
            {
                $query = $db->simple_select("smartlinks", "slid", "word = '" . $db->escape_string($mybb->input['word']) . "'");

                if ($db->num_rows($query))
                {
                    $errors[] = $lang->error_smartlink_filtered;
                }
            }

            $word = str_replace('\*', '([a-zA-Z0-9_]{1})', preg_quote($mybb->input['word'], "#"));

            if (strlen($mybb->input['word']) == strlen($mybb->input['url']) && preg_match("#(^|\W)" . $word . "(\W|$)#i", $mybb->input['url']))
            {
                $errors[] = $lang->error_smartlink_url_word_invalid;
            }

            if ($mybb->input['title_type'] == 2)
            {
                $title_checked[1] = '';
                $title_checked[2] = "checked=\"checked\"";

                if (!$mybb->input['urltitle'])
                {
                    $errors[] = $lang->error_smartlink_url_title_empty;
                }

                if (strlen($mybb->input['urltitle']) > 100)
                {
                    $errors[] = $lang->smartlink_url_title_max;
                }
            }
            else
            {
                $title_checked[1] = "checked=\"checked\"";
                $title_checked[2] = '';

                $mybb->input['urltitle'] = '';
            }

            if (!$errors)
            {
                $new_smartlink = array(
                    "word" => $db->escape_string($mybb->input['word']),
                    "url" => $db->escape_string($mybb->input['url']),
                    "urltitle" => $db->escape_string($mybb->input['urltitle']),
                    "nofollow" => $mybb->get_input('nofollow', MyBB::INPUT_INT),
                    "newtab" => $mybb->get_input('newtab', MyBB::INPUT_INT)
                );

                $slid = $db->insert_query("smartlinks", $new_smartlink);

                log_admin_action($slid, $mybb->input['word'], $mybb->input['url']);

                mybbsmartlinks_cache();
                flash_message($lang->success_added_smartlink, 'success');
                admin_redirect("index.php?module=config-mybbsmartlinks");
            }
        }

        $page->add_breadcrumb_item($lang->add_smartlink);
        $page->output_header($lang->smartlinks . " - " . $lang->add_smartlink);

        $sub_tabs['smartlinks'] = array(
            'title' => $lang->smartlink_filters,
            'description' => $lang->smartlink_filters_desc,
            'link' => "index.php?module=config-mybbsmartlinks"
        );

        $sub_tabs['addsmartlink'] = array(
            'title' => $lang->add_smartlink,
            'description' => $lang->add_smartlink_desc,
            'link' => "index.php?module=config-mybbsmartlinks&amp;action=add"
        );

        $page->output_nav_tabs($sub_tabs, "addsmartlink");

        $form = new Form("index.php?module=config-mybbsmartlinks&amp;action=add", "post", "add");

        if ($errors)
        {
            $page->output_inline_error($errors);
        }
        else
        {
            $mybb->input['nofollow'] = '0';
            $mybb->input['newtab'] = '0';
            $title_checked[1] = "checked=\"checked\"";
            $title_checked[2] = '';
            $mybb->input['urltitle'] = '';
        }

        $form_container = new FormContainer($lang->add_smartlink);
        $form_container->output_row($lang->smartlink . " <em>*</em>", $lang->smartlink_desc, $form->generate_text_box('word', $mybb->input['word'], array('id' => 'word')), 'word');
        $form_container->output_row($lang->smartlink_url . " <em>*</em>", $lang->smartlink_url_desc, $form->generate_text_box('url', $mybb->input['url'], array('id' => 'url')), 'url');
        $actions = "<script type=\"text/javascript\">
        function checkAction(id)
        {
            var checked = '';

            $('.'+id+'s_check').each(function(e, val)
            {
                if($(this).prop('checked') == true)
                {
                    checked = $(this).val();
                }
            });
            $('.'+id+'s').each(function(e)
            {
                $(this).hide();
            });
            if($('#'+id+'_'+checked))
            {
                $('#'+id+'_'+checked).show();
            }
        }
        </script>
        <dl style=\"margin-top: 0; margin-bottom: 0; width: 100%;\">
            <dt><label style=\"display: block;\"><input type=\"radio\" name=\"title_type\" value=\"1\" {$title_checked[1]} class=\"titles_check\" onclick=\"checkAction('title');\" style=\"vertical-align: middle;\" /> <strong>{$lang->no}</strong></label></dt>
            <dt><label style=\"display: block;\"><input type=\"radio\" name=\"title_type\" value=\"2\" {$title_checked[2]} class=\"titles_check\" onclick=\"checkAction('title');\" style=\"vertical-align: middle;\" /> <strong>{$lang->yes}</strong></label></dt>
            <dd style=\"margin-top: 4px;\" id=\"title_2\" class=\"titles\">
                <table cellpadding=\"4\">
                    <tr>
                        <td><small>{$lang->smartlink_url_title}</small></td>
                        <td>" . $form->generate_text_box('urltitle', $mybb->input['urltitle']) . "</td>
                    </tr>
                </table>
            </dd>
        </dl>
        <script type=\"text/javascript\">
            checkAction('title');
        </script>";
        $form_container->output_row($lang->smartlink_url_title_addtitle, '', $actions);
        $form_container->output_row($lang->smartlink_url_nofollow, '', $form->generate_yes_no_radio('nofollow', $mybb->input['nofollow'], array('style' => 'width: 2em;')));
        $form_container->output_row($lang->smartlink_url_newtab, '', $form->generate_yes_no_radio('newtab', $mybb->input['newtab'], array('style' => 'width: 2em;')));
        $form_container->end();
        $buttons[] = $form->generate_submit_button($lang->save_smartlink);
        $form->output_submit_wrapper($buttons);
        $form->end();

        $page->output_footer();
    }

    if ($mybb->input['action'] == "delete")
    {
        $query = $db->simple_select("smartlinks", "*", "slid='" . $mybb->get_input('slid', MyBB::INPUT_INT) . "'");
        $smartlink = $db->fetch_array($query);

        if (!$smartlink['slid'])
        {
            flash_message($lang->error_invalid_slid, 'error');
            admin_redirect("index.php?module=config-mybbsmartlinks");
        }

        if ($mybb->input['no'])
        {
            admin_redirect("index.php?module=config-mybbsmartlinks");
        }

        if ($mybb->request_method == "post")
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

    if ($mybb->input['action'] == "edit")
    {
        $query = $db->simple_select("smartlinks", "*", "slid='" . $mybb->get_input('slid', MyBB::INPUT_INT) . "'");
        $smartlink = $db->fetch_array($query);

        if (!$smartlink['slid'])
        {
            flash_message($lang->error_invalid_slid, 'error');
            admin_redirect("index.php?module=config-mybbsmartlinks");
        }

        if ($mybb->request_method == "post")
        {
            $mybb->input['word'] = trim($mybb->input['word']);
            $mybb->input['url'] = trim($mybb->input['url']);
            $mybb->input['urltitle'] = trim($mybb->input['urltitle']);

            if (!$mybb->input['word'])
            {
                $errors[] = $lang->error_missing_smartlink;
            }

            if (preg_match('/\b(mybb)\b/i', $mybb->input['word']))
            {
                $errors[] = $lang->sprintf($lang->smartlink_word_invalid, $mybb->input['word']);
            }

            if (strlen($mybb->input['word']) > 100)
            {
                $errors[] = $lang->smartlink_max;
            }

            if (!preg_match('/^(http:\/\/www\.|https:\/\/www\.|http:\/\/|https:\/\/|www\.)/i', $mybb->input['url']))
            {
                $errors[] = $lang->smartlink_url_invalid;
            }

            if (strlen($mybb->input['url']) > 200)
            {
                $errors[] = $lang->smartlink_url_word_max;
            }

            $word = str_replace('\*', '([a-zA-Z0-9_]{1})', preg_quote($mybb->input['word'], "#"));

            if (strlen($mybb->input['word']) == strlen($mybb->input['url']) && preg_match("#(^|\W)" . $word . "(\W|$)#i", $mybb->input['url']))
            {
                $errors[] = $lang->error_smartlink_url_word_invalid;
            }

            if ($mybb->input['title_type'] == 2)
            {
                $title_checked[1] = '';
                $title_checked[2] = "checked=\"checked\"";

                if (!$mybb->input['urltitle'])
                {
                    $errors[] = $lang->error_smartlink_url_title_empty;
                }

                if (strlen($mybb->input['urltitle']) > 100)
                {
                    $errors[] = $lang->smartlink_url_title_max;
                }
            }
            else
            {
                $title_checked[1] = "checked=\"checked\"";
                $title_checked[2] = '';

                $mybb->input['urltitle'] = '';
            }

            if (!$errors)
            {
                $updated_smartlink = array(
                    "word" => $db->escape_string($mybb->input['word']),
                    "url" => $db->escape_string($mybb->input['url']),
                    "urltitle" => $db->escape_string($mybb->input['urltitle']),
                    "nofollow" => $mybb->get_input('nofollow', MyBB::INPUT_INT),
                    "newtab" => $mybb->get_input('newtab', MyBB::INPUT_INT)
                );

                $db->update_query("smartlinks", $updated_smartlink, "slid='{$smartlink['slid']}'");

                log_admin_action($smartlink['slid'], $mybb->input['word'], $mybb->input['url']);

                mybbsmartlinks_cache();

                flash_message($lang->success_updated_smartlink, 'success');
                admin_redirect("index.php?module=config-mybbsmartlinks");
            }
        }

        $page->add_breadcrumb_item($lang->edit_smartlink);
        $page->output_header($lang->smartlinks . " - " . $lang->edit_smartlink);

        $sub_tabs['smartlinks'] = array(
            'title' => $lang->smartlink_filters,
            'description' => $lang->smartlink_filters_desc,
            'link' => "index.php?module=config-mybbsmartlinks"
        );

        $sub_tabs['addsmartlink'] = array(
            'title' => $lang->add_smartlink,
            'description' => $lang->add_smartlink_desc,
            'link' => "index.php?module=config-mybbsmartlinks&amp;action=add"
        );

        $sub_tabs['editsmartlink'] = array(
            'title' => $lang->edit_smartlink,
            'description' => $lang->edit_smartlink_desc,
            'link' => "index.php?module=config-mybbsmartlinks&amp;action=edit&amp;slid={$smartlink['slid']}"
        );

        $page->output_nav_tabs($sub_tabs, "editsmartlink");

        $form = new Form("index.php?module=config-mybbsmartlinks&amp;action=edit&amp;slid={$smartlink['slid']}", "post");

        if ($errors)
        {
            $page->output_inline_error($errors);
            $smartlink_data = $mybb->input;
        }
        else
        {
            $smartlink_data = $smartlink;
            if (!$smartlink['urltitle'])
            {
                $title_checked[1] = "checked=\"checked\"";
                $title_checked[2] = '';
            }
            else
            {
                $title_checked[1] = '';
                $title_checked[2] = "checked=\"checked\"";
            }

            $mybb->input['urltitle'] = $smartlink['urltitle'];
        }

        $form_container = new FormContainer($lang->edit_smartlink);
        $form_container->output_row($lang->smartlink . " <em>*</em>", $lang->smartlink_desc, $form->generate_text_box('word', $smartlink_data['word'], array('id' => 'word')), 'word');
        $form_container->output_row($lang->smartlink_url, $lang->smartlink_url_desc, $form->generate_text_box('url', $smartlink_data['url'], array('id' => 'url')), 'url');
        $actions = "<script type=\"text/javascript\">
        function checkAction(id)
        {
            var checked = '';

            $('.'+id+'s_check').each(function(e, val)
            {
                if($(this).prop('checked') == true)
                {
                    checked = $(this).val();
                }
            });
            $('.'+id+'s').each(function(e)
            {
                $(this).hide();
            });
            if($('#'+id+'_'+checked))
            {
                $('#'+id+'_'+checked).show();
            }
        }
    </script>
    <dl style=\"margin-top: 0; margin-bottom: 0; width: 100%;\">
        <dt><label style=\"display: block;\"><input type=\"radio\" name=\"title_type\" value=\"1\" {$title_checked[1]} class=\"titles_check\" onclick=\"checkAction('title');\" style=\"vertical-align: middle;\" /> <strong>{$lang->no}</strong></label></dt>
        <dt><label style=\"display: block;\"><input type=\"radio\" name=\"title_type\" value=\"2\" {$title_checked[2]} class=\"titles_check\" onclick=\"checkAction('title');\" style=\"vertical-align: middle;\" /> <strong>{$lang->yes}</strong></label></dt>
        <dd style=\"margin-top: 4px;\" id=\"title_2\" class=\"titles\">
            <table cellpadding=\"4\">
                <tr>
                    <td><small>{$lang->smartlink_url_title}</small></td>
                    <td>" . $form->generate_text_box('urltitle', $mybb->input['urltitle']) . "</td>
                </tr>
            </table>
        </dd>
    </dl>
    <script type=\"text/javascript\">
        checkAction('title');
    </script>";
        $form_container->output_row($lang->smartlink_url_title_addtitle, '', $actions);
        $form_container->output_row($lang->smartlink_url_nofollow, '', $form->generate_yes_no_radio('nofollow', $smartlink_data['nofollow'], array('style' => 'width: 2em;')));
        $form_container->output_row($lang->smartlink_url_newtab, '', $form->generate_yes_no_radio('newtab', $smartlink_data['newtab'], array('style' => 'width: 2em;')));
        $form_container->end();
        $buttons[] = $form->generate_submit_button($lang->save_smartlink);
        $form->output_submit_wrapper($buttons);
        $form->end();

        $page->output_footer();
    }

    if (!$mybb->input['action'])
    {
        $page->output_header($lang->smartlinks);

        $sub_tabs['smartlinks'] = array(
            'title' => $lang->smartlink_filters,
            'description' => $lang->smartlink_filters_desc,
            'link' => "index.php?module=config-mybbsmartlinks"
        );

        $sub_tabs['addsmartlink'] = array(
            'title' => $lang->add_smartlink,
            'description' => $lang->add_smartlink_desc,
            'link' => "index.php?module=config-mybbsmartlinks&amp;action=add"
        );

        $page->output_nav_tabs($sub_tabs, "smartlinks");

        $query = $db->simple_select("smartlinks", "COUNT(slid) AS smartlinks");
        $total_rows = $db->fetch_field($query, "smartlinks");

        $pagenum = $mybb->get_input('page', MyBB::INPUT_INT);
        if($pagenum)
        {
            $start = ($pagenum - 1) * 20;
            $pages = ceil($total_rows / 20);
            if($pagenum > $pages)
            {
                $start = 0;
                $pagenum = 1;
            }
        }
        else
        {
            $start = 0;
            $pagenum = 1;
        }

        $table = new Table;
        $table->construct_header($lang->smartlink, array('width' => '27%'));
        $table->construct_header($lang->smartlink_url, array('width' => '28%'));
        $table->construct_header($lang->smartlink_title, array('width' => '25%'));
        $table->construct_header($lang->smartlink_nofollow, array('class' => 'align_center', 'width' => '7%'));
        $table->construct_header($lang->smartlink_newtab, array('class' => 'align_center', 'width' => '7%'));
        $table->construct_header($lang->controls, array('class' => 'align_center', 'width' => '6%'));

        $query = $db->simple_select("smartlinks", "*", "", array('limit_start' => $start, 'limit' => 20, "order_by" => "word", "order_dir" => "asc"));
        while ($smartlink = $db->fetch_array($query))
        {
            $smartlink['word'] = htmlspecialchars_uni($smartlink['word']);
            $smartlink['url'] = htmlspecialchars_uni($smartlink['url']);

            if (strlen($smartlink['url']) > 74)
            {
                $smartlink['url'] = substr_replace($smartlink['url'], '....', 35, strlen($smartlink['url']) - 70);
            }

            $url_title = !empty($smartlink['urltitle']) ? $smartlink['urltitle'] : $lang->smartlink_url_notitle;
            $nofollow_status = $smartlink['nofollow'] == 1 ? $lang->yes : $lang->no;
            $newtab_status = $smartlink['newtab'] == 1 ? $lang->yes : $lang->no;

            $table->construct_cell($smartlink['word']);
            $table->construct_cell($smartlink['url'], array('style' => 'word-break: break-all;'));
            $table->construct_cell($url_title, array('style' => 'word-break: break-all;'));
            $table->construct_cell($nofollow_status, array('class' => 'align_center'));
            $table->construct_cell($newtab_status, array('class' => 'align_center'));
            $popup = new PopupMenu("smartlinks_{$smartlink['slid']}", $lang->options);
            $popup->add_item($lang->smartlink_edit_option, "index.php?module=config-mybbsmartlinks&amp;action=edit&amp;slid={$smartlink['slid']}");
            $popup->add_item($lang->smartlink_delete_option, "index.php?module=config-mybbsmartlinks&amp;action=delete&amp;slid={$smartlink['slid']}&amp;my_post_key={$mybb->post_code}", "return AdminCP.deleteConfirmation(this, '{$lang->confirm_smartlink_deletion}')");
            $table->construct_cell($popup->fetch(), array('class' => 'align_center'));
            $table->construct_row();
        }

        if ($table->num_rows() == 0)
        {
            $table->construct_cell($lang->no_smartlinks, array("colspan" => 7));
            $table->construct_row();
        }

        $table->output($lang->smartlink_filters);

        echo "<br />".draw_admin_pagination($pagenum, "20", $total_rows, "index.php?module=config-mybbsmartlinks&amp;page={page}");

        $page->output_footer();
    }
}

function mybbsmartlinks_tools_cache_rebuild()
{
    global $cache;
    class MybbSmartLinksCache extends datacache
    {
        function update_smartlinks()
        {
            mybbsmartlinks_cache();
        }
    }
    $cache = null;
    $cache = new MybbSmartLinksCache;
}

function mybbsmartlinks_parse_message($message)
{
    global $cache;
    $slcache = $cache->read('smartlinks');

    if (is_array($slcache))
    {
        reset($slcache);
        foreach ($slcache as $slid => $smartlink)
        {
            $urlextras = '';

            if (!preg_match('/^((http|https):\/\/)/i', $smartlink['url']))
            {
                $smartlink['url'] = 'http://' . $smartlink['url'];
            }

            if (!empty($smartlink['urltitle']))
            {
                $urlextras .= ' title="' . htmlspecialchars_uni($smartlink['urltitle']) . '"';
            }

            if ($smartlink['nofollow'] == 1)
            {
                $urlextras .= ' rel="nofollow"';
            }

            if ($smartlink['newtab'] == 1)
            {
                $urlextras .= ' target="_blank"';
            }
            else
            {
                $urlextras .= ' target="_self"';
            }

            $smartlink['word'] = str_replace('\*', '([a-zA-Z0-9_]{1})', preg_quote($smartlink['word'], "#"));

            $ignore_tags = implode('|', array('code', 'pre', 'script', 'blockquote'));

            $message = preg_replace("#<a.*?<\/a>(*SKIP)(*F)|(^|\W)(?<![\/>])(?=(?:(?:[^<]++|<(?!\/?(?:" . $ignore_tags . ")\b))*+)(?:<(?>" . $ignore_tags . ")\b|\z))" . $smartlink['word'] . "(?=\W|$)#i", '\1<a href="' . $smartlink['url'] . '"' . $urlextras . ' class="smartlink_' . $smartlink['slid'] . '">' . trim($smartlink['word']) . '</a>', $message);
        }
    }
    return $message;
}

function mybbsmartlinks_cache($clear = false, $remove = false)
{
    global $cache;
    if ($clear == true)
    {
        $cache->update('smartlinks', false);

        if ($remove == true)
        {
            $cache->delete('smartlinks');
        }
    }
    else
    {
        global $db;
        $smartlinks = array();
        $query = $db->simple_select('smartlinks', 'slid,word,url,urltitle,nofollow,newtab');
        while ($smartlink = $db->fetch_array($query))
        {
            $smartlinks[$smartlink['slid']] = $smartlink;
        }
        $cache->update('smartlinks', $smartlinks);
    }
}
