<?php

require 'lib/hm-imap.php';

class Hm_Handler_prep_imap_summary_display extends Hm_Handler_Module {
    public function process($data) {
        list($success, $form) = $this->process_form(array('summary_ids'));
        if ($success) {
            $ids = explode(',', $form['summary_ids']);
            foreach($ids as $id) {
                $id = intval($id);
                $details = Hm_IMAP_List::dump($id);
                $cache = Hm_IMAP_List::get_cache($this->session, $id);
                $imap = Hm_IMAP_List::connect($id, $cache);
                if (is_object($imap) && $imap->get_state() == 'authenticated') {
                    $data['imap_summary'][$id] = $imap->get_mailbox_status('INBOX');
                    $data['imap_summary'][$id]['folders'] = count($imap->get_mailbox_list());
                }
                else {
                    if (!$imap) {
                        Hm_Msgs::add(sprintf('ERRCould not access IMAP server "%s" (%s:%d)', $details['name'], $details['server'], $details['port']));
                    }
                    $data['imap_summary'][$id] = array('folders' => '?', 'messages' => '?', 'unseen' => '?');
                }
            }
        }
        return $data;
    }
}

class Hm_Handler_imap_unread extends Hm_Handler_Module {
    public function process($data) {
        list($success, $form) = $this->process_form(array('imap_unread_ids'));
        if ($success) {
            $data['imap_unread_unchanged'] = false;
            $ids = explode(',', $form['imap_unread_ids']);
            $msg_list = array();
            $cached = 0;
            foreach($ids as $id) {
                $id = intval($id);
                $cache = Hm_IMAP_List::get_cache($this->session, $id);
                $imap = Hm_IMAP_List::connect($id, $cache);
                if ($imap) {
                    $imap->read_only = true;
                    $server_details = Hm_IMAP_List::dump($id);
                    if ($imap->select_mailbox('INBOX')) {
                        $unseen = $imap->search('UNSEEN');
                        if ($imap->cached_response) {
                            $cached++;
                        }
                        if ($unseen) {
                            $msgs = $imap->get_message_list($unseen);
                            foreach ($msgs as $msg) {
                                $msg['server_id'] = $id;
                                $msg['server_name'] = $server_details['name'];
                                $msg_list[] = $msg;
                            }
                        }
                    }
                }
            }
            if ($cached == count($ids)) {
                $data['imap_unread_unchanged'] = true;
            }
            else {
                usort($msg_list, function($a, $b) {
                    if ($a['date'] == $b['date']) return 0;
                    return (strtotime($a['date']) > strtotime($b['date']))? -1 : 1;
                });
                $data['imap_unread_data'] = $msg_list;
            }
        }
        return $data;
    }
}

class Hm_Handler_process_add_imap_server extends Hm_Handler_Module {
    public function process($data) {
        if (isset($this->request->post['submit_imap_server'])) {
            list($success, $form) = $this->process_form(array('new_imap_name', 'new_imap_address', 'new_imap_port'));
            if (!$success) {
                $data['old_form'] = $form;
                Hm_Msgs::add('ERRYou must supply a name, a server and a port');
            }
            else {
                $tls = false;
                if (isset($this->request->post['tls'])) {
                    $tls = true;
                }
                if ($con = fsockopen($form['new_imap_address'], $form['new_imap_port'], $errno, $errstr, 2)) {
                    Hm_IMAP_List::add(array(
                        'name' => $form['new_imap_name'],
                        'server' => $form['new_imap_address'],
                        'port' => $form['new_imap_port'],
                        'tls' => $tls));
                    Hm_Msgs::add('Added server!');
                }
                else {
                    Hm_Msgs::add(sprintf('ERRCound not add server: %s', $errstr));
                }
            }
        }
        return $data;
    }
}

class Hm_Handler_save_imap_cache extends Hm_Handler_Module {
    public function process($data) {
        $cache = $this->session->get('imap_cache', array());
        $servers = Hm_IMAP_List::dump(false, true);
        foreach ($servers as $index => $server) {
            if (isset($server['object']) && is_object($server['object'])) {
                $cache[$index] = $server['object']->dump_cache('gzip');
            }
        }
        if (count($cache) > 0) {
            $this->session->set('imap_cache', $cache);
            Hm_Debug::add(sprintf('Cached data for %d IMAP connections', count($cache)));
        }
        return $data;
    }
}

class Hm_Handler_save_imap_servers extends Hm_Handler_Module {
    public function process($data) {
        $servers = Hm_IMAP_List::dump();
        $cache = $this->session->get('imap_cache', array());
        $new_cache = array();
        foreach ($cache as $index => $cache_str) {
            if (isset($servers[$index])) {
                $new_cache[$index] = $cache_str;
            }
        }
        $this->user_config->set('imap_servers', $servers);
        $this->session->set('imap_cache', $new_cache);
        Hm_IMAP_List::clean_up();
        return $data;
    }
}

class Hm_Handler_load_imap_servers_from_config extends Hm_Handler_Module {
    public function process($data) {
        $servers = $this->user_config->get('imap_servers', array());
        $added = false;
        foreach ($servers as $index => $server) {
            Hm_IMAP_List::add($server, $index);
            if ($server['name'] == 'Default-Auth-Server') {
                $added = true;
            }
        }
        if (!$added) {
            $auth_server = $this->session->get('imap_auth_server_settings', array());
            if (!empty($auth_server)) {
                Hm_IMAP_List::add(array( 
                    'name' => 'Default-Auth-Server',
                    'server' => $auth_server['server'],
                    'port' => $auth_server['port'],
                    'tls' => $auth_server['tls'],
                    'user' => $auth_server['username'],
                    'pass' => $auth_server['password']),
                count($servers));
                $this->session->del('imap_auth_server_settings');
            }
        }
        return $data;
    }
}

class Hm_Handler_add_imap_servers_to_page_data extends Hm_Handler_Module {
    public function process($data) {
        $data['imap_servers'] = array();
        $servers = Hm_IMAP_List::dump();
        if (!empty($servers)) {
            $data['imap_servers'] = $servers;
        }
        return $data;
    }
}

class Hm_Handler_imap_bust_cache extends Hm_Handler_Module {
    public function process($data) {
        $this->session->set('imap_cache', array());
        return $data;
    }
}

class Hm_Handler_imap_connect extends Hm_Handler_Module {
    public function process($data) {
        if (isset($this->request->post['imap_connect'])) {
            list($success, $form) = $this->process_form(array('imap_user', 'imap_pass', 'imap_server_id'));
            $imap = false;
            $cache = Hm_IMAP_List::get_cache($this->session, $form['imap_server_id']);
            if ($success) {
                $imap = Hm_IMAP_List::connect($form['imap_server_id'], $cache, $form['imap_user'], $form['imap_pass']);
            }
            elseif (isset($form['imap_server_id'])) {
                $imap = Hm_IMAP_List::connect($form['imap_server_id'], $cache);
            }
            if ($imap) {
                if ($imap->get_state() == 'authenticated') {
                    Hm_Msgs::add("Successfully authenticated to the IMAP server");
                }
                else {
                    Hm_Msgs::add("ERRFailed to authenticate to the IMAP server");
                }
            }
            else {
                Hm_Msgs::add('ERRUsername and password are required');
                $data['old_form'] = $form;
            }
        }
        return $data;
    }
}

class Hm_Handler_imap_forget extends Hm_Handler_Module {
    public function process($data) {
        $data['just_forgot_credentials'] = false;
        if (isset($this->request->post['imap_forget'])) {
            list($success, $form) = $this->process_form(array('imap_server_id'));
            if ($success) {
                Hm_IMAP_List::forget_credentials($form['imap_server_id']);
                $data['just_forgot_credentials'] = true;
                Hm_Msgs::add('Server credentials forgotten');
            }
            else {
                $data['old_form'] = $form;
            }
        }
        return $data;
    }
}

class Hm_Handler_imap_save extends Hm_Handler_Module {
    public function process($data) {
        $data['just_saved_credentials'] = false;
        if (isset($this->request->post['imap_save'])) {
            list($success, $form) = $this->process_form(array('imap_user', 'imap_pass', 'imap_server_id'));
            if (!$success) {
                Hm_Msgs::add('ERRUsername and Password are required to save a connection');
            }
            else {
                $cache = Hm_IMAP_List::get_cache($this->session, $form['imap_server_id']);
                $imap = Hm_IMAP_List::connect($form['imap_server_id'], $cache, $form['imap_user'], $form['imap_pass'], true);
                if ($imap->get_state() == 'authenticated') {
                    $data['just_saved_credentials'] = true;
                    Hm_Msgs::add("Server saved");
                }
                else {
                    Hm_Msgs::add("ERRUnable to save this server, are the username and password correct?");
                }
            }
        }
        return $data;
    }
}

class Hm_Handler_imap_delete extends Hm_Handler_Module {
    public function process($data) {
        if (isset($this->request->post['imap_delete'])) {
            list($success, $form) = $this->process_form(array('imap_server_id'));
            if ($success) {
                $res = Hm_IMAP_List::del($form['imap_server_id']);
                if ($res) {
                    $data['deleted_server_id'] = $form['imap_server_id'];
                    Hm_Msgs::add('Server deleted');
                }
            }
            else {
                $data['old_form'] = $form;
            }
        }
        return $data;
    }
}

class Hm_Output_display_configured_imap_servers extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '';
        if ($format == 'HTML5') {
            $res = '';
            foreach ($input['imap_servers'] as $index => $vals) {

                if (isset($vals['user'])) {
                    $disabled = 'disabled="disabled"';
                    $user_pc = $vals['user'];
                    $pass_pc = '[saved]';
                }
                else {
                    $user_pc = '';
                    $pass_pc = 'Password';
                    $disabled = '';
                }
                if ($vals['name'] == 'Default-Auth-Server') {
                    $vals['name'] = 'Default';
                }
                $res .= '<div class="configured_server">';
                $res .= sprintf('<div class="server_title">IMAP %s</div><div class="server_subtitle">%s/%d %s</div>',
                    $this->html_safe($vals['name']), $this->html_safe($vals['server']), $this->html_safe($vals['port']),
                    $vals['tls'] ? 'TLS' : '' );
                $res .= 
                    '<form class="imap_connect" method="POST">'.
                    '<input type="hidden" name="imap_server_id" value="'.$this->html_safe($index).'" /><span> '.
                    '<input '.$disabled.' class="credentials" placeholder="Username" type="text" name="imap_user" value="'.$user_pc.'"></span>'.
                    '<span> <input '.$disabled.' class="credentials imap_password" placeholder="'.$pass_pc.'" type="password" name="imap_pass"></span>';
                if ($vals['name']) {
                    $res .= '<input type="submit" value="Test" class="test_imap_connect" />';
                    if (!isset($vals['user']) || !$vals['user']) {
                        $res .= '<input type="submit" value="Delete" class="imap_delete" />';
                        $res .= '<input type="submit" value="Save" class="save_imap_connection" />';
                    }
                    else {
                        $res .= '<input type="submit" value="Delete" class="imap_delete" />';
                        $res .= '<input type="submit" value="Forget" class="forget_imap_connection" />';
                    }
                    $res .= '<input type="hidden" value="ajax_imap_debug" name="hm_ajax_hook" />';
                }
                $res .= '</form></div>';
            }
        }
        return $res;
    }
}

class Hm_Output_add_imap_server_dialog extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($format == 'HTML5') {
            return '<form class="add_server" method="POST">'.
                '<table>'.
                '<tr><td><input type="text" name="new_imap_name" class="txt_fld" value="" placeholder="Account name" /></td></tr>'.
                '<tr><td><input type="text" name="new_imap_address" class="txt_fld" placeholder="IMAP server address" value=""/></td></tr>'.
                '<tr><td><input type="text" name="new_imap_port" class="port_fld" value="" placeholder="Port"></td></tr>'.
                '<tr><td><input type="checkbox" name="tls" value="1" checked="checked" /> Use TLS</td></tr>'.
                '<tr><td><input type="submit" value="Add IMAP Server" name="submit_imap_server" /></td></tr>'.
                '</table></form>';
        }
    }
}

class Hm_Output_display_imap_summary extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($format == 'HTML5') {
            $res = '';
            if (isset($input['imap_servers']) && !empty($input['imap_servers'])) {
                $res .= '<input type="hidden" id="imap_summary_ids" value="'.
                    $this->html_safe(implode(',', array_keys($input['imap_servers']))).'" />';
                $res .= '<div class="imap_summary_data">';
                $res .= '<table><thead><tr><th>IMAP Server</th><th>Address</th><th>Port</th>'.
                    '<th>TLS</th><th>Folders</th><th>INBOX count</th><th>INBOX unread</th></tr></thead><tbody>';
                foreach ($input['imap_servers'] as $index => $vals) {
                    if ($vals['name'] == 'Default-Auth-Server') {
                        $vals['name'] = 'Default';
                    }
                    $res .= '<tr class="imap_summary_'.$index.'"><td>'.$vals['name'].'</td>'.
                        '<td>'.$vals['server'].'</td><td>'.$vals['port'].'</td>'.
                        '<td>'.$vals['tls'].'</td><td class="folders"></td>'.
                        '<td class="total"></td><td class="unseen"></td>'.
                        '</tr>';
                }
                $res .= '</table></div>';
            }
            else {
                $res .= '<div class="imap_summary_data"><table class="empty_table"><tr><td>No IMAP servers found. '.
                    '<a href="'.$input['router_url_path'].'?page=servers">Add some</a></td></tr></table></div>';
            }
            return $res;
        }
    }
}

class Hm_Output_jquery_table extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($format == 'HTML5' ) {
            return '<script type="text/javascript" src="modules/imap/jquery.tablesorter.min.js"></script>';
        }
        return '';
    }
}

class Hm_Output_unread_message_list extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($format == 'HTML5') {
            $res = '';
            if (isset($input['imap_servers'])) {
                $res .= '<input type="hidden" id="imap_unread_ids" value="'.$this->html_safe(implode(',', array_keys($input['imap_servers']))).'" />';
            }
            $res .= '<div class="unread_messages">'.
                '<table><tr><td class="empty_table"><img src="images/ajax-loader.gif" width="16" height="16" alt="" />&nbsp; Loading unread messages ...</td></tr></table></div>';
            return $res;
        }
    }
}

class Hm_Output_filter_unread_data extends Hm_Output_Module {
    protected function output($input, $format) {
        $clean = array();
        if (isset($input['imap_unread_data'])) {
            $res = '<table><thead><tr><th>Source</th><th>Subject</th><th>From</th><th>Date</th></tr><tbody>';
            foreach($input['imap_unread_data'] as $msg) {
                $clean = array_map(function($v) { return $this->html_safe($v); }, $msg);
                if ($clean['server_name'] == 'Default-Auth-Server') {
                    $clean['server_name'] = 'Default';
                }
                $subject = preg_replace("/(\[.+\])/U", '<span class="hl">$1</span>', $clean['subject']);
                $from = preg_replace("/(\&lt;.+\&gt;)/U", '<span class="dl">$1</span>', $clean['from']);
                $from = str_replace("&quot;", '', $from);
                $date = date('Y-m-d g:i:s', strtotime($clean['date']));
                $res .= '<tr><td><div class="source">'.$clean['server_name'].'</div></td>'.
                    '<td><div class="subject">'.$subject.'</div></td>'.
                    '<td><div class="from">'.$from.'</div></td>'.
                    '<td><div class="msg_date">'.$date.'</div></td></tr>';
            }
            if (!count($input['imap_unread_data'])) {
                $res .= '<tr><td colspan="4" class="empty_table">No unread message found</td></tr>';
            }
            $res .= '</tbody></table>';
            $input['formatted_unread_data'] = $res;
        }
        else {
            $input['formatted_unread_data'] = '';
        }
        return $input;
    }
}

?>
