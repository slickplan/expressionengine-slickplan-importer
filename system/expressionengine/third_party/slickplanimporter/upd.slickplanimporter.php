<?php defined('BASEPATH') or exit('No direct script access allowed');

class Slickplanimporter_upd {

	public $version = '1.0.0';

    /**
     * Install
     *
     * @return bool
     */
	public function install()
	{
        ee()->db->insert('modules', array(
            'module_name' => 'Slickplanimporter',
            'module_version' => $this->version,
            'has_cp_backend' => 'y',
            'has_publish_fields' => 'n',
        ));

        $fields = array(
            'id' => array('type' => 'int', 'constraint' => '10', 'unsigned' => TRUE, 'null' => FALSE, 'auto_increment' => TRUE),
            'xml' => array('type' => 'longtext', 'null' => FALSE)
        );
        ee()->load->dbforge();
        ee()->dbforge->add_field($fields);
        ee()->dbforge->add_key('id', TRUE);
        ee()->dbforge->create_table('slickplan_importer');
        return true;
	}

    /**
     * Uninstall
     *
     * @return bool
     */
	public function uninstall()
	{
        $mod_id = ee()->db->select('module_id')
            ->get_where('modules', array(
                'module_name'	=> 'Slickplanimporter'
            ))->row('module_id');
        ee()->db->where('module_id', $mod_id)
            ->delete('module_member_groups');
        ee()->db->where('module_name', 'Slickplanimporter')
            ->delete('modules');

        ee()->load->dbforge();
        ee()->dbforge->drop_table('slickplan_importer');
        return true;
	}

    /**
     * Update
     *
     * @param string $current
     * @return bool
     */
    public function update($current = '')
    {
        return true;
    }

}