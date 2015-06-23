<div id="slickplan-importer" class="options">
    <?php
    echo form_open($form_action_url);
    if (isset($errors) and !empty($errors)) {
        slickplaninporter_message_box($errors, 'error');
    }
    ?>
    <div class="input buttons">
        <?php
        echo $html;
        $data = array(
            'name' => 'slickplan_submit',
            'class' => 'submit',
            'value' => 'Import',
        );
        echo form_submit($data);
        ?>
    </div>
    <?php
    echo form_close();
    ?>
</div>
<script type="text/javascript">
    jQuery(document).ready(function($) {
        var channels = <?php echo json_encode($channels); ?>;

        var $wrapper = $('#slickplan-importer');

        $wrapper
            .on('change', '.slickplan-title', function() {
                var $title = $(this).closest('tr.row').find('.slickplan-page-title');
                var title = $title.data('original') + '';
                if (this.value === 'ucwords') {
                    title = title
                        .toLowerCase()
                        .replace(/^([a-z\u00E0-\u00FC])|\s+([a-z\u00E0-\u00FC])/g, function($1) {
                            return $1.toUpperCase();
                        });
                }
                else if (this.value === 'ucfirst') {
                    title = title.charAt(0).toUpperCase() + title.substr(1).toLowerCase();
                }
                $title.text(title);
            })
            .on('click', '.slickplan-repeat, .slickplan-repeat-checked', function(e) {
                e.preventDefault();
                var $tr = $(this).closest('tr.row');
                var titles = $tr.find('.slickplan-title').val();
                var content = $tr.find('.slickplan-content:checked').val();
                var content_files = $tr.find('.slickplan-content-files').val();
                var channel = $tr.find('.slickplan-channel').val();
                var channel_field = $tr.find('.slickplan-channel-field').val();
                var $rows = $tr.siblings('tr.row');
                if ($(this).hasClass('slickplan-repeat-checked')) {
                    $rows = $rows.find('input.slickplan-check:checked').closest('tr.row');
                }
                $rows.each(function() {
                    var $this = $(this);
                    $this.find('.slickplan-title').val(titles).trigger('change');
                    $this.find('.slickplan-content[value="' + content + '"]').prop('checked', true).trigger('change');
                    $this.find('.slickplan-content-files').val(content_files);
                    $this.find('.slickplan-channel').val(channel).trigger('change');
                    setTimeout(function() {
                        $this.find('.slickplan-channel-field').val(channel_field);
                    }, 10);
                });
            })
            .on('change', '.slickplan-content', function() {
                var $files = $(this).closest('td').find('.slickplan-option-files');
                var $channels = $(this).closest('tr.row').find('.slickplan-channels').show();
                if (this.value === 'contents') {
                    $files.show();
                }
                else {
                    if (this.value === '') {
                        $channels.hide();
                    }
                    $files.hide();
                }
            })
            .on('change', '.slickplan-channel', function() {
                var fields = (channels && channels[this.value] && channels[this.value]['fields'])
                    ? channels[this.value]['fields']
                    : {};
                var options = '';
                $.each(fields, function(field_id, field_name) {
                    options += '<option value="' + field_id + '">' + field_name + '</option>'
                });
                $(this).closest('tr.row').find('.slickplan-channel-field').html(options);
            })
        ;

        $(window).on('load', function() {
            $wrapper.find('input[type="radio"]:checked, select.slickplan-title').trigger('change');
        });
    });
</script>