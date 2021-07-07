<?php defined('BASEPATH') or exit('No direct script access allowed');

function_exists('ob_start') and ob_start();
function_exists('set_time_limit') and set_time_limit(600);

require_once dirname(__FILE__) . '/includes/helpers.php';

class Slickplanimporter_mcp
{

    /**
     * @var array
     */
    public $options = array(
        'titles' => '',
        'content' => '',
        'content_files' => false,
        'users' => array(),
        'channel' => 0,
        'field' => 0,
    );

    /**
     * @var array
     */
    public $import_options = array(
        'internal_links' => array(),
        'imported_pages' => array(),
    );

    /**
     * @var array
     */
    public $summary = array();

    /**
     * @var string
     */
    private $_form_path = '';

    /**
     * @var string
     */
    private $_base_url = '';

    /**
     * @var array
     */
    private $_files = array();

    /**
     * @var array
     */
    private $_ee_data = array();

    /**
     * If page has unparsed internal pages
     *
     * @var bool
     */
    private $_has_unparsed_internal_links = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_checkRequirements();

        ee()->load->helper('form');
        ee()->load->library('api');
        ee()->load->model('field_model');
        ee()->load->model('category_model');

        ee()->api->instantiate('channel_entries');
        ee()->api->instantiate('channel_structure');
        ee()->api->instantiate('channel_fields');

        $this->_ee_data['site_id'] = ee()->config->item('site_id');

        $this->_form_path = 'C=addons_modules' . AMP . 'M=show_module_cp' . AMP . 'module=slickplanimporter';
        $this->_base_url = BASE . AMP . $this->_form_path;

        ee()->cp->add_to_head('<link rel="stylesheet" href="' . URL_THIRD_THEMES . 'slickplanimporter/styles.css">');
        ee()->view->cp_page_title = 'Slickplan Importer';
    }

    /**
     * @see this->upload()
     */
    public function index()
    {
        return $this->upload();
    }

    /**
     * XML file upload page
     *
     * @return mixed
     */
    public function upload()
    {
        $errors = '';

        if (isset($_FILES['slickplan_file']) and is_array($_FILES['slickplan_file'])) {
            $upload_error_strings = array(
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.',
            );
            $file = $_FILES['slickplan_file'];
            if (!isset($file['tmp_name']) or !is_file($file['tmp_name'])) {
                $file['error'] = UPLOAD_ERR_NO_FILE;
            }
            try {
                if (isset($file['error'], $upload_error_strings[$file['error']]) and intval($file['error']) > 0) {
                    throw new Exception($upload_error_strings[$file['error']]);
                }
                $xml = (isset($_FILES['slickplan_file']['tmp_name']) and is_file($_FILES['slickplan_file']['tmp_name']))
                    ? file_get_contents($_FILES['slickplan_file']['tmp_name'])
                    : '';
                $xml = $this->_parseSlickplanXml($xml);
                $this->_saveXml($xml);
                ee()->functions->redirect($this->_base_url . AMP . 'method=options');
            } catch (Exception $e) {
                $errors = $e->getMessage();
            }
        }

        return ee()->load->view('upload', array(
            'form_action_url' => $this->_form_path,
            'errors' => $errors,
        ), true);
    }

    /**
     * Import options page
     *
     * @return mixed
     */
    public function options()
    {
        $channels = ee()->api_channel_structure
            ->get_channels($this->_ee_data['site_id'])
            ->result_array();
        $this->_ee_data['channels'] = array();
        foreach ($channels as $channel) {
            $this->_ee_data['channels'][$channel['channel_id']] = array(
                'name' => $channel['channel_title'],
                'fields' => array(),
            );
            if (isset($channel['field_group']) and !is_null($channel['field_group'])) {
                $custom_fields = ee()->field_model
                    ->get_fields($channel['field_group'], array(
                        'site_id' => $this->_ee_data['site_id'],
                    ))
                    ->result_array();
                foreach ($custom_fields as $custom_field) {
                    $this->_ee_data['channels'][$channel['channel_id']]['fields'][$custom_field['field_id']]
                        = $custom_field['field_label'];
                }
            }
        }

        ee()->load->model('file_upload_preferences_model');
        $this->_ee_data['upload_dirs'] = ee()->file_upload_preferences_model->get_file_upload_preferences();

        $xml = $this->_getSavedXml();
        $this->import_options = isset($xml['import_options']) ? $xml['import_options'] : array();

        if (isset($_POST['slickplan_importer']) and is_array($_POST['slickplan_importer'])) {
            foreach ($_POST['slickplan_importer'] as $page_id => $page_data) {
                if (intval($page_data['import']) === 1 and isset($xml['pages'][$page_id])) {
                    $page_data['content_files'] = (
                        isset($page_data['content'], $page_data['content_files'])
                        and $page_data['content'] === 'contents'
                        and $page_data['content_files']
                    );
                    $xml['pages'][$page_id]['_options'] = $page_data;
                }
            }
            $this->_saveXml($xml);
            ee()->functions->redirect($this->_base_url . AMP . 'method=ajax');
        }

        return ee()->load->view('options', array(
            'form_action_url' => $this->_base_url . AMP . 'method=options',
            'xml' => $xml,
            'html' => $this->_displayPagesArray($xml),
            'channels' => $this->_ee_data['channels'],
        ), true);
    }

    /**
     * AJAX importer page
     *
     * @return mixed
     */
    public function ajax()
    {
        $xml = $this->_getSavedXml();
        $this->import_options = isset($xml['import_options']) ? $xml['import_options'] : array();

        ee()->cp->add_to_head('<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css">');

        if (isset($_POST['slickplan']) and is_array($_POST['slickplan'])) {
            $form = $_POST['slickplan'];
            $result = array();
            if (isset($xml['pages'][$form['page']]) and is_array($xml['pages'][$form['page']])) {
                $mlid = (isset($form['mlid']) and $form['mlid'])
                    ? $form['mlid']
                    : 0;
                $page = $this->_importPage($xml['pages'][$form['page']], $mlid);
                $result = $page;
                $result['html'] = $this->_getSummaryRow($page);
            }
            if (isset($form['last']) and $form['last']) {
                $result['last'] = $form['last'];
                $this->_checkForInternalLinks();
                $this->_deleteXml();
            } else {
                $this->_saveXml($xml);
            }
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        return ee()->load->view('ajax', array(
            'form_action_url' => $this->_base_url . AMP . 'method=ajax',
            'xml' => $xml,
            'html' => $this->_getSummaryRow(array(
                'title' => '{title}',
                'loading' => 1,
            )),
        ), true);
    }

    /**
     * Display pages mapping.
     *
     * @param array $xml
     * @return string
     */
    private function _displayPagesArray(array $xml)
    {
        $html = '<table cellspacing="0" cellpadding="0" border="0" class="mainTable padTable">'
            . '<thead><tr class="even">'
            . '<th style="width: 18px;" class="header">'
            . '<input type="checkbox" class="slickplan-check-all" checked>'
            . '</th><th class="header">Page</th>'
            . '<th>Content Setting</th><th></th></tr>'
            . '</thead><tbody>';
        if (isset($xml['sitemap'], $xml['pages'])) {
            foreach (array('home', '1', 'util', 'foot') as $type) {
                if (isset($xml['sitemap'][$type]) and is_array($xml['sitemap'][$type])) {
                    $html .= $this->_displayPages($xml['sitemap'][$type], $xml['pages']);
                }
            }
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Display page mapping element.
     *
     * @param array $array
     * @param array $pages
     * @param int $indent
     * @return string
     */
    private function _displayPages(array $array, array $pages, $indent = 0)
    {
        $html = '';
        foreach ($array as $page) {
            if (isset($page['id'], $pages[$page['id']])) {
                $field_name = 'slickplan_importer[' . $page['id'] . ']';
                $title = htmlspecialchars($this->_getFormattedTitle($pages[$page['id']]));
                $html .= '<tr class="row"><td><input type="checkbox" class="slickplan-check" name="'
                    . $field_name . '[import]" value="1" checked></td>'
                    . '<td><strong>';
                if ($indent) {
                    $html .= '<span class="slickplan-child-icon" style="padding-left: '
                        . ($indent * 13)
                        . 'px;">↳</span> ';
                }
                $html .= '<label class="slickplan-page-title" data-original="' . $title . '">'
                    . $title . '</label></strong>';
                $html .= '</td><td valign="top">'
                    . '<table cellspacing="0" cellpadding="0" border="0" class="slickplan-table"><tr><td>';
                $html .= '<div class="slickplan-option">'
                    . '<label><input type="radio" name="' . $field_name . '[content]" value="contents" class="slickplan-content" checked> Import page content from Content Planner</label>'
                    . '<label class="slickplan-margin"><input type="radio" name="' . $field_name . '[content]" value="desc" class="slickplan-content"> Import notes as page content</label>'
                    . '<label class="slickplan-margin"><input type="radio" name="' . $field_name . '[content]" value="" class="slickplan-content"> Don&#8217;t import any content</label>'
                    . '</div>'
                    . '<div class="slickplan-option slickplan-option-files">';

                $html .= '<label>Import files to:</label> <select name="' . $field_name . '[content_files]" class="slickplan-content-files">'
                    . '<option value="0">Don\'t import files</option>';
                foreach ($this->_ee_data['upload_dirs'] as $upload_id => $upload_name) {
                    $html .= '<option value="' . $upload_id . '">' . $upload_name['name'] . '</option>';
                }
                $html .= '</select></div>';

                if ($this->_ee_data['channels']) {
                    $html .= '<div class="slickplan-option slickplan-channels">';
                    $html .= '<label>Channel:</label> <select name="' . $field_name . '[channel]" class="slickplan-channel">';
                    foreach ($this->_ee_data['channels'] as $channel_id => $channel_data) {
                        $html .= '<option value="' . $channel_id . '">'
                            . htmlspecialchars($channel_data['name']) . '</option>';
                    }
                    $html .= '</select>';
                    $html .= '<label class="slickplan-margin">Content Field:</label> <select name="' . $field_name . '[field]" class="slickplan-channel-field">';
                    foreach ($this->_ee_data['channels'] as $channel_id => $channel_data) {
                        foreach ($channel_data['fields'] as $field_id => $field_name) {
                            $html .= '<option value="' . $field_id . '">'
                                . htmlspecialchars($field_name) . '</option>';
                        }
                        break;
                    }
                    $html .= '</select></div>';
                }
                $html .= '<div class="slickplan-option"><label>Title:</label> <select name="' . $field_name . '[titles]" class="slickplan-title">'
                    . '<option value=""> No change</option>'
                    . '<option value="ucfirst"> Make the first character uppercase</option>'
                    . '<option value="ucwords"> Uppercase the first character of each word</option>'
                    . '</select></div>';
                $html .= '</td></tr></table></td><td><label>Repeat this configuration:</label><br>'
                    . '<button class="slickplan-repeat">To all pages</button><br>'
                    . '<button class="slickplan-repeat-checked">To selected pages</button>'
                    . '</td></tr>';
                if (isset($page['childs']) and is_array($page['childs']) and count($page['childs'])) {
                    $html .= $this->_displayPages($page['childs'], $pages, $indent + 1);
                }
            }
        }
        return $html;
    }

    /**
     * Check all module's requirements
     */
    private function _checkRequirements()
    {
        if (!ee()->cp->allowed_group('can_access_content')) {
            show_error(lang('unauthorized_access'));
        }

        $installed_modules = ee()->cp->get_installed_modules();

        if (!array_key_exists('channel', $installed_modules)) {
            show_error('Channel module is required');
        }

        if (!array_key_exists('file', $installed_modules)) {
            show_error('File module is required');
        } else {
            ee()->load->model('file_upload_preferences_model');
            $uploads = ee()->file_upload_preferences_model->get_file_upload_preferences();
            if (!count($uploads)) {
                show_error('No upload locations available, at least one location is required');
            }
        }

        if (!array_key_exists('pages', $installed_modules)) {
            show_error('Pages module is required');
        }

        ee()->load->model('channel_model');
        $channels = ee()->functions->fetch_assigned_channels();
        if (!is_array($channels) or !count($channels)) {
            show_error('At least one channel is required');
        }
    }

    /**
     * Parse Slickplan's XML file. Converts an XML DOMDocument to an array.
     *
     * @param $input_xml
     * @return array
     * @throws Exception
     */
    private function _parseSlickplanXml($input_xml)
    {
        $input_xml = trim($input_xml);
        if (substr($input_xml, 0, 5) === '<?xml') {
            $xml = new DomDocument('1.0', 'UTF-8');
            $xml->xmlStandalone = false;
            $xml->formatOutput = true;
            $xml->loadXML($input_xml);
            if (isset($xml->documentElement->tagName) and $xml->documentElement->tagName === 'sitemap') {
                $array = $this->_parseSlickplanXmlNode($xml->documentElement);
                if ($this->_isCorrectSlickplanXmlFile($array)) {
                    if (isset($array['diagram'])) {
                        unset($array['diagram']);
                    }
                    if (isset($array['section']['options'])) {
                        $array['section'] = array($array['section']);
                    }
                    $array['sitemap'] = $this->_getMultidimensionalArrayHelper($array);
                    $array['users'] = array();
                    $array['pages'] = array();
                    foreach ($array['section'] as $section_key => $section) {
                        if (isset($section['cells']['cell']) and is_array($section['cells']['cell'])) {
                            foreach ($section['cells']['cell'] as $cell_key => $cell) {
                                if (
                                    isset($section['options']['id'], $cell['level'])
                                    and $cell['level'] === 'home'
                                    and $section['options']['id'] !== 'svgmainsection'
                                ) {
                                    unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                                }
                                if (isset(
                                    $cell['contents']['assignee']['@value'],
                                    $cell['contents']['assignee']['@attributes']
                                )) {
                                    $array['users'][$cell['contents']['assignee']['@value']]
                                        = $cell['contents']['assignee']['@attributes'];
                                }
                                if (isset($cell['@attributes']['id'])) {
                                    $array['pages'][$cell['@attributes']['id']] = $cell;
                                }
                            }
                        }
                    }
                    unset($array['section']);
                    return $array;
                }
            }
        }
        throw new Exception('Invalid file format.');
    }

    /**
     * Add a file to Media Library from URL
     *
     * @param $url
     * @param array $attrs Assoc array of attributes [title, alt, description, file_name]
     * @return bool|string
     */
    private function _addMedia($url, array $attrs = array())
    {
        if (!$this->options['content_files']) {
            return false;
        }
        if (!isset($attrs['file_name']) or !$attrs['file_name']) {
            $url = parse_url($url);
            $attrs['file_name'] = basename($url['path']);
        }

        ee()->load->library('filemanager');
        ee()->load->model('file_model');
        ee()->load->helper('file');
        ee()->load->model('file_upload_preferences_model');

        $prefs = ee()->file_upload_preferences_model
            ->get_file_upload_preferences('1', $this->options['content_files'], false);

        $file = ee()->filemanager->clean_filename($attrs['file_name'], $this->options['content_files'], array(
            'ignore_dupes' => false,
        ));
        file_put_contents($file, file_get_contents($url));

        $mime = get_mime_by_extension($file);
        $is_image = ee()->filemanager->is_image($mime);
        $filename = basename($file);
        $url = (isset($prefs['url']) ? $prefs['url'] : '') . $filename;
        $path = dirname($file);
        if ($is_image) {
            $dimensions = ee()->file_model->get_dimensions_by_dir_id($this->options['content_files'])->result_array();
            ee()->filemanager->create_thumb(
                $file,
                array(
                    'server_path' => $path,
                    'file_name' => $filename,
                    'mime_type' => $mime,
                    'dimensions' => $dimensions,
                ),
                true,
                false
            );
        }
        $thumb_info = ee()->filemanager->get_thumb($filename, $this->options['content_files']);
        $file_data = array(
            'upload_location_id' => $this->options['content_files'],
            'site_id' => ee()->config->item('site_id'),
            'file_name' => $filename,
            'orig_name' => $attrs['file_name'],
            'file_data_orig_name' => $attrs['file_name'],
            'is_image' => $is_image,
            'mime_type' => $mime,
            'rel_path' => $file,
            'file_thumb' => $thumb_info['thumb'],
            'thumb_class' => $thumb_info['thumb_class'],
            'modified_by_member_id' => ee()->session->userdata('member_id'),
            'uploaded_by_member_id' => ee()->session->userdata('member_id'),
            'file_size' => is_file($file) ? filesize($file) : 0,
        );
        $file_id = ee()->file_model->save_file($file_data);

        if ($file_id !== false) {
            $this->_files[] = array(
                'url' => $url,
                'filename' => $file_data['orig_name'],
            );
            return $url;
        }
        ee()->file_model->delete_raw_file($filename, $this->options['content_files'], false);
        $this->_files[] = array(
            'error' => 'Error while saving file',
            'filename' => $file_data['orig_name'],
        );
        return false;
    }

    /**
     * Import single page into EE.
     *
     * @param array $data
     * @param int $parent_id
     */
    private function _importPage(array $data, $parent_id = 0)
    {
        $this->options = array(
            'titles' => '',
            'content' => '',
            'content_files' => false,
            'users' => array(),
            'channel' => 0,
            'field' => 0,
        );
        if (isset($data['_options'])) {
            $this->options = array_merge($this->options, $data['_options']);
        }
        $this->_files = array();

        $page = array(
            'title' => $this->_getFormattedTitle($data),
            'structure__parent_id' => $parent_id,
            'cp_call' => true,
        );

        // Set url slug
        if (isset($data['contents']['url_slug']) and $data['contents']['url_slug']) {
            $page['url_title'] = str_replace('%page_name%', $page['title'], $page['url_title']);
            $page['url_title'] = str_replace('%separator%', '-', $page['url_title']);
        }

        // Set post author
        if (isset(
            $data['contents']['assignee']['@value'],
            $this->options['users'][$data['contents']['assignee']['@value']]
        )) {
            $page['author'] = $this->options['users'][$data['contents']['assignee']['@value']];
        }

        // Set post content
        if ($this->options['content'] === 'desc') {
            if (isset($data['desc']) and !empty($data['desc'])) {
                $page['field_id_' . $this->options['field']] = $data['desc'];
            }
        } elseif ($this->options['content'] === 'contents') {
            if (
                isset($data['contents']['body'])
                and is_array($data['contents']['body'])
                and count($data['contents']['body'])
            ) {
                $page['field_id_' . $this->options['field']] = $this->_getFormattedContent($data['contents']['body']);
            }
        }

        // Check if page has internal links, we need to replace them later
        $this->_has_unparsed_internal_links = false;
        if (isset($page['field_id_' . $this->options['field']]) and $page['field_id_' . $this->options['field']]) {
            $updated_content = $this->_parseInternalLinks($page['field_id_' . $this->options['field']]);
            if ($updated_content) {
                $page['field_id_' . $this->options['field']] = $updated_content;
            }
        }

        $templates = array();
        $tquery = ee()->db->query("SELECT exp_template_groups.group_name, exp_templates.template_name, exp_templates.template_id
            FROM exp_template_groups, exp_templates
            WHERE exp_template_groups.group_id = exp_templates.group_id
            AND exp_templates.site_id = '" . ee()->db->escape_str(ee()->config->item('site_id')) . "'");
        foreach ($tquery->result_array() as $row) {
            $templates[$row['template_id']] = $row['group_name'].'/'.$row['template_name'];
        }

        ee()->api_channel_fields->setup_entry_settings($this->options['channel'], $page);
        $success = ee()->api_channel_entries->save_entry($page, $this->options['channel']);
        if ($success) {
            $url = '/';
            $channel_data = ee()->api_channel_structure->channel_info;
            if (is_array($channel_data)) {
                $channel_data = array_shift($channel_data);
                if (isset($channel_data->row_data['live_look_template'], $templates[$channel_data->row_data['live_look_template']])) {
                    $url = ee()->functions->create_url($templates[$channel_data->row_data['live_look_template']] . '/' . ee()->api_channel_entries->entry_id);
                }
            }

            $return = array(
                'ID' => ee()->api_channel_entries->entry_id,
                'title' => $page['title'],
                'url' => $url,
                'mlid' => ee()->api_channel_entries->entry_id,
                'files' => $this->_files,
            );

            // Save page permalink
            if (isset($data['@attributes']['id'])) {
                $this->import_options['imported_pages'][$data['@attributes']['id']] = $return['url'];
            }

            // Check if page has unparsed internal links, we need to replace them later
            if ($this->_has_unparsed_internal_links) {
                $this->import_options['internal_links'][] = array(
                    'entry_id' => $return['ID'],
                    'field_id' => $this->options['field'],
                    'chanel_id' => $this->options['channel'],
                    'content' => isset($page['field_id_' . $this->options['field']])
                        ? $page['field_id_' . $this->options['field']]
                        : '',
                );
            }
        } else {
            $return = array(
                'title' => $page['title'],
                'error' => implode($this->api_channel_entries->errors),
            );
        }

        $this->summary[] = $this->_getSummaryRow($return);
        return $return;
    }

    /**
     * Get HTML of a summary row
     *
     * @param array $page
     * @param null $id
     * @return string
     */
    private function _getSummaryRow(array $page)
    {
        $html = '<div style="margin: 10px 0;">Importing „<b>' . $page['title'] . '</b>”&hellip;<br />';
        if (isset($page['error']) and $page['error']) {
            $html .= '<span style="color: #e00"><i class="fa fa-fw fa-times"></i> ' . $page['error'] . '</span>';
        } elseif (isset($page['url'])) {
            $html .= '<i class="fa fa-fw fa-check" style="color: #0d0"></i>';
//                . '<a href="' . $page['url'] . '">' . $page['url'] . '</a>';
        } elseif (isset($page['loading']) and $page['loading']) {
            $html .= '<i class="fa fa-fw fa-refresh fa-spin"></i>';
        }
        if (isset($page['files']) and is_array($page['files']) and count($page['files'])) {
            $files = array();
            foreach ($page['files'] as $file) {
                if (isset($file['url']) and $file['url']) {
                    $files[] = '<i class="fa fa-fw fa-check" style="color: #0d0"></i> <a href="'
                        . $file['url'] . '" target="_blank">' . $file['filename'] . '</a>';
                } elseif (isset($file['error']) and $file['error']) {
                    $files[] = '<span style="color: #e00"><i class="fa fa-fw fa-times"></i> '
                        . $file['filename'] . ' - ' . $file['error'] . '</span>';
                }
            }
            $html .= '<div style="border-left: 5px solid rgba(0, 0, 0, 0.05); margin-left: 5px; '
                . 'padding: 5px 0 5px 11px;">Files:<br />' . implode('<br />', $files) . '</div>';
        }
        $html .= '<div>';
        return $html;
    }

    /**
     * Get formatted HTML content.
     *
     * @param array $content
     */
    private function _getFormattedContent(array $contents)
    {
        $post_content = array();
        foreach ($contents as $type => $content) {
            if (isset($content['content'])) {
                $content = array($content);
            }
            foreach ($content as $element) {
                if (!isset($element['content'])) {
                    continue;
                }
                $html = '';
                switch ($type) {
                    case 'wysiwyg':
                        $html .= $element['content'];
                        break;
                    case 'text':
                        $html .= htmlspecialchars($element['content']);
                        break;
                    case 'image':
                        foreach ($this->_getMediaElementArray($element) as $item) {
                            if (isset($item['type'], $item['url'])) {
                                $attrs = array(
                                    'alt' => isset($item['alt'])
                                        ? $item['alt']
                                        : '',
                                    'title' => isset($item['title'])
                                        ? $item['title']
                                        : '',
                                    'file_name' => isset($item['file_name'])
                                        ? $item['file_name']
                                        : '',
                                );
                                if ($item['type'] === 'library') {
                                    $src = $this->_addMedia($item['url'], $attrs);
                                } else {
                                    $src = $item['url'];
                                }
                                if ($src and is_string($src)) {
                                    $html .= '<img src="' . htmlspecialchars($src)
                                        . '" alt="' . htmlspecialchars($attrs['alt'])
                                        . '" title="' . htmlspecialchars($attrs['title']) . '" />';
                                }
                            }
                        }
                        break;
                    case 'video':
                    case 'file':
                        foreach ($this->_getMediaElementArray($element) as $item) {
                            if (isset($item['type'], $item['url'])) {
                                $attrs = array(
                                    'description' => isset($item['description'])
                                        ? $item['description']
                                        : '',
                                    'file_name' => isset($item['file_name'])
                                        ? $item['file_name']
                                        : '',
                                );
                                if ($item['type'] === 'library') {
                                    $src = $this->_addMedia($item['url'], $attrs);
                                    $name = basename($src);
                                } else {
                                    $src = $item['url'];
                                    $name = $src;
                                }
                                if ($src and is_string($src)) {
                                    $name = $attrs['description']
                                        ? $attrs['description']
                                        : ($attrs['file_name'] ? $attrs['file_name'] : $name);
                                    $html .= '<a href="' . htmlspecialchars($src) . '" title="'
                                        . htmlspecialchars($attrs['description']) . '">' . $name . '</a>';
                                }
                            }
                        }
                        break;
                    case 'table':
                        if (isset($element['content']['data'])) {
                            if (!is_array($element['content']['data'])) {
                                $element['content']['data'] = @json_decode($element['content']['data'], true);
                            }
                            if (is_array($element['content']['data'])) {
                                $html .= '<table>';
                                foreach ($element['content']['data'] as $row) {
                                    $html .= '<tr>';
                                    foreach ($row as $cell) {
                                        $html .= '<td>' . $cell . '</td>';
                                    }
                                    $html .= '</tr>';
                                }
                                $html .= '<table>';
                            }
                        }
                        break;
                }
                if ($html) {
                    $prepend = '';
                    $append = '';
                    if (isset($element['options']['tag']) and $element['options']['tag']) {
                        $element['options']['tag'] = preg_replace('/[^a-z]+/', '',
                            strtolower($element['options']['tag']));
                        if ($element['options']['tag']) {
                            $prepend = '<' . $element['options']['tag'];
                            if (isset($element['options']['tag_id']) and $element['options']['tag_id']) {
                                $prepend .= ' id="' . htmlspecialchars($element['options']['tag_id']) . '"';
                            }
                            if (isset($element['options']['tag_class']) and $element['options']['tag_class']) {
                                $prepend .= ' class="' . htmlspecialchars($element['options']['tag_class']) . '"';
                            }
                            $prepend .= '>';
                        }
                    }
                    if (isset($element['options']['tag']) and $element['options']['tag']) {
                        $append = '</' . $element['options']['tag'] . '>';
                    }
                    $post_content[] = $prepend . $html . $append;
                }
            }
        }
        return implode("\n\n", $post_content);
    }

    /**
     * Reformat title.
     *
     * @param $data
     * @return string
     */
    private function _getFormattedTitle(array $data)
    {
        $title = (isset($data['contents']['page_title']) and $data['contents']['page_title'])
            ? $data['contents']['page_title']
            : (isset($data['text']) ? $data['text'] : '');
        if ($this->options['titles'] === 'ucfirst') {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title);
                $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
            } else {
                $title = ucfirst(strtolower($title));
            }
        } elseif ($this->options['titles'] === 'ucwords') {
            if (function_exists('mb_convert_case')) {
                $title = mb_convert_case($title, MB_CASE_TITLE);
            } else {
                $title = ucwords(strtolower($title));
            }
        }
        return $title;
    }

    /**
     * Parse single node XML element.
     *
     * @param DOMElement $node
     * @return array|string
     */
    private function _parseSlickplanXmlNode($node)
    {
        if (isset($node->nodeType)) {
            if ($node->nodeType === XML_CDATA_SECTION_NODE or $node->nodeType === XML_TEXT_NODE) {
                return trim($node->textContent);
            } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                $output = array();
                for ($i = 0, $j = $node->childNodes->length; $i < $j; ++$i) {
                    $child_node = $node->childNodes->item($i);
                    $value = $this->_parseSlickplanXmlNode($child_node);
                    if (isset($child_node->tagName)) {
                        if (!isset($output[$child_node->tagName])) {
                            $output[$child_node->tagName] = array();
                        }
                        $output[$child_node->tagName][] = $value;
                    } elseif ($value !== '') {
                        $output = $value;
                    }
                }

                if (is_array($output)) {
                    foreach ($output as $tag => $value) {
                        if (is_array($value) and count($value) === 1) {
                            $output[$tag] = $value[0];
                        }
                    }
                    if (empty($output)) {
                        $output = '';
                    }
                }

                if ($node->attributes->length) {
                    $attributes = array();
                    foreach ($node->attributes as $attr_name => $attr_node) {
                        $attributes[$attr_name] = (string)$attr_node->value;
                    }
                    if (!is_array($output)) {
                        $output = array(
                            '@value' => $output,
                        );
                    }
                    $output['@attributes'] = $attributes;
                }
                return $output;
            }
        }
        return array();
    }

    /**
     * Check if the array is from a correct Slickplan XML file.
     *
     * @param array $array
     * @param bool $parsed
     * @return bool
     */
    private function _isCorrectSlickplanXmlFile($array, $parsed = false)
    {
        $first_test = (
            $array
            and is_array($array)
            and isset($array['title'], $array['version'], $array['link'])
            and is_string($array['link']) and strstr($array['link'], 'slickplan.')
        );
        if ($first_test) {
            if ($parsed) {
                if (isset($array['sitemap']) and is_array($array['sitemap'])) {
                    return true;
                }
            } elseif (
                isset($array['section']['options']['id'], $array['section']['cells'])
                or isset($array['section'][0]['options']['id'], $array['section'][0]['cells'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get multidimensional array, put all child pages as nested array of the parent page.
     *
     * @param array $array
     * @return array
     */
    private function _getMultidimensionalArrayHelper(array $array)
    {
        $cells = array();
        $main_section_key = -1;
        $relation_section_cell = array();
        foreach ($array['section'] as $section_key => $section) {
            if (
                isset($section['@attributes']['id'], $section['cells']['cell'])
                and is_array($section['cells']['cell'])
            ) {
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    if (isset($cell['@attributes']['id'])) {
                        $cell_id = $cell['@attributes']['id'];
                        if (isset($cell['section']) and $cell['section']) {
                            $relation_section_cell[$cell['section']] = $cell_id;
                        }
                    } else {
                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                    }
                }
            } else {
                unset($array['section'][$section_key]);
            }
        }
        foreach ($array['section'] as $section_key => $section) {
            $section_id = $section['@attributes']['id'];
            if ($section_id !== 'svgmainsection') {
                $remove = true;
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $cell['level'] = (string)$cell['level'];
                    if ($cell['level'] === 'home') {
                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                    } elseif ($cell['level'] === '1' and isset($relation_section_cell[$section_id])) {
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['parent']
                            = $relation_section_cell[$section_id];
                        $remove = false;
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] *= 10;
                    }
                }
                if ($remove) {
                    unset($array['section'][$section_key]);
                }
            } else {
                $main_section_key = $section_key;
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] /= 1000;
                }
            }
        }
        foreach ($array['section'] as $section_key => $section) {
            $section_cells = array();
            foreach ($section['cells']['cell'] as $cell_key => $cell) {
                $section_cells[] = $cell;
            }
            usort($section_cells, array($this, '_sortPages'));
            $array['section'][$section_key]['cells']['cell'] = $section_cells;
            $cells = array_merge($cells, $section_cells);
            unset($section_cells);
        }
        $multi_array = array();
        if (isset($array['section'][$main_section_key]['cells']['cell'])) {
            foreach ($array['section'][$main_section_key]['cells']['cell'] as $cell) {
                if (isset($cell['@attributes']['id']) and (
                        $cell['level'] === 'home' or $cell['level'] === 'util' or $cell['level'] === 'foot'
                        or $cell['level'] === '1' or $cell['level'] === 1
                    )
                ) {
                    $level = $cell['level'];
                    if (!isset($multi_array[$level]) or !is_array($multi_array[$level])) {
                        $multi_array[$level] = array();
                    }
                    $childs = $this->_getMultidimensionalArray($cells, $cell['@attributes']['id']);
                    $cell = array(
                        'id' => $cell['@attributes']['id'],
                        'title' => $this->_getFormattedTitle($cell),
                    );
                    if ($childs) {
                        $cell['childs'] = $childs;
                    }
                    $multi_array[$level][] = $cell;
                }
            }
        }
        unset($array, $cells, $relation_section_cell);
        return $multi_array;
    }

    /**
     * Put all child pages as nested array of the parent page.
     *
     * @param array $array
     * @param $parent
     * @param $summary
     * @return array
     */
    private function _getMultidimensionalArray(array $array, $parent)
    {
        $cells = array();
        foreach ($array as $cell) {
            if (isset($cell['parent'], $cell['@attributes']['id']) and $cell['parent'] === $parent) {
                $childs = $this->_getMultidimensionalArray($array, $cell['@attributes']['id']);
                $cell = array(
                    'id' => $cell['@attributes']['id'],
                    'title' => $this->_getFormattedTitle($cell),
                );
                if ($childs) {
                    $cell['childs'] = $childs;
                }
                $cells[] = $cell;
            }
        }
        return $cells;
    }

    /**
     * Sort cells.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private function _sortPages(array &$a, array &$b)
    {
        if (isset($a['order'], $b['order'])) {
            return ($a['order'] < $b['order']) ? -1 : 1;
        }
        return 0;
    }

    /**
     * Save XML file.
     *
     * @param array $xml
     */
    private function _saveXml(array $xml)
    {
        $xml['import_options'] = $this->import_options;
        ee()->db->insert('slickplan_importer', array(
            'xml' => serialize($xml),
        ));
    }

    /**
     * Get saved XML file.
     *
     * @param bool $return_raw
     * @return bool|array
     */
    private function _getSavedXml($return_raw = false)
    {
        $result = ee()->db->select('xml')
            ->limit(1)
            ->order_by('id', 'desc')
            ->get('slickplan_importer');
        if ($result->num_rows() > 0) {
            $row = $result->result();
            if (isset($row[0]->xml)) {
                if ($return_raw) {
                    return $row[0];
                }
                $val = $row[0]->xml;
                $val = unserialize($val);
                return $val;
            }
        }
        return false;
    }

    /**
     * Delete saved XMLs
     *
     * @return bool
     */
    private function _deleteXml()
    {
        do {
            $row = $this->_getSavedXml(true);
            ee()->db->delete('slickplan_importer', array('id' => $row->id));
        } while ($row and $row->id);
        return true;
    }

    /**
     * Replace internal links with correct pages URLs.
     *
     * @param $content
     * @param $force_parse
     * @return bool
     */
    private function _parseInternalLinks($content, $force_parse = false)
    {
        preg_match_all('/href="slickplan:([a-z0-9]+)"/isU', $content, $internal_links);
        if (isset($internal_links[1]) and is_array($internal_links[1]) and count($internal_links[1])) {
            $internal_links = array_unique($internal_links[1]);
            $links_replace = array();
            foreach ($internal_links as $cell_id) {
                if (
                    isset($this->import_options['imported_pages'][$cell_id])
                    and $this->import_options['imported_pages'][$cell_id]
                ) {
                    $links_replace['="slickplan:' . $cell_id . '"'] = '="'
                        . htmlspecialchars($this->import_options['imported_pages'][$cell_id]) . '"';
                } elseif ($force_parse) {
                    $links_replace['="slickplan:' . $cell_id . '"'] = '="#"';
                } else {
                    $this->_has_unparsed_internal_links = true;
                }
            }
            if (count($links_replace)) {
                return strtr($content, $links_replace);
            }
        }
        return false;
    }

    /**
     * Check if there are any pages with unparsed internal links, if yes - replace links with real URLs
     */
    private function _checkForInternalLinks()
    {
        if (isset($this->import_options['internal_links']) and is_array($this->import_options['internal_links'])) {
            foreach ($this->import_options['internal_links'] as $data) {
                if ($data['field'] and ee()->api_channel_entries->entry_exists($data['entry_id'])) {
                    $page_content = $this->_parseInternalLinks($data['content'], true);
                    if ($page_content) {
                        $page = array();
                        $page['field_id_' . $data['field']] = $page_content;
                        ee()->api_channel_fields->setup_entry_settings($data['channel_id'], $page);
                        ee()->api_channel_entries->save_entry($page, $data['chanel_id'], $data['entry_id']);
                    }
                }
            }
        }
    }

    /**
     * @param array $element
     * @return array
     */
    private function _getMediaElementArray(array $element): array
    {
        $items = $element['content']['contentelement'] ?? $element['content'];
        return isset($items['type'])
            ? [$items]
            : (isset($items[0]['type']) ? $items : []);
    }

}
