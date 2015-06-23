<div id="slickplan-importer" class="upload">
    <?php
    echo form_open_multipart($form_action_url);
    if (isset($errors) and !empty($errors)) {
        slickplaninporter_message_box($errors, 'error');
    } else {
        slickplaninporter_message_box(array(
            'This importer allows you to import pages structure from a '
                . '<a href="http://slickplan.com/" target="_blank">Slickplan</a>’s '
                . 'XML file into your ExpressionEngine site.',
            'Pick a XML file to upload and click Upload.',
        ));
    }
    $data = array(
        'name' => 'slickplan_file',
    );
    $errors = form_error('slickplan_file', ' ', ' ');
    if (!empty($errors)) {
        $data['class'] = 'error';
        $data['title'] = $errors;
    }
    ?>
    <div class="input upload">
        <?php echo form_upload($data); ?>
    </div>
    <div class="input buttons">
        <?php
        $data = array(
            'name' => 'slickplan_submit',
            'class' => 'submit',
            'value' => 'Upload',
        );
        echo form_submit($data);
        ?>
    </div>
    <?php
    echo form_close();
    ?>
</div>