<div id="slickplan-importer" class="ajax">
    <h3>Importing Pages&hellip;</h3>
    <div class="alert alert-success slickplan-show-summary" role="alert" style="display: none">
        <p>Pages have been imported. Thank you for using <a href="http://slickplan.com/" target="_blank">Slickplan</a> Importer.</p>
    </div>
    <div id="slickplan-progressbar" class="progressbar"><div class="ui-progressbar-value"><div class="progress-label">0%</div></div></div>
    <p><hr></p>
    <div class="slickplan-summary"></div>
</div>
<script type="text/javascript">
    jQuery(document).ready(function($) {
        var SLICKPLAN_JSON = <?php echo json_encode($xml['sitemap']); ?>;
        var SLICKPLAN_HTML = '<?php echo addslashes($html); ?>';

        var $form = $('#slickplan-importer');
        var $summary = $form.find('.slickplan-summary');
        var $progress = $('#slickplan-progressbar');

        var _pages = [];
        var _importIndex = 0;

        var _generatePagesFlatArray = function(pages, parent) {
            $.each(pages, function(index, data) {
                if (data.id) {
                    _pages.push({
                        id: data.id,
                        parent: parent,
                        title: data.title
                    });
                    if (data.childs) {
                        _generatePagesFlatArray(data.childs, data.id);
                    }
                }
            });
        };

        var _addMenuID = function(parent_id, mlid) {
            for (var i = 0; i < _pages.length; ++i) {
                if (_pages[i].parent === parent_id) {
                    _pages[i].mlid = mlid;
                }
            }
        };

        var _importPage = function(page) {
            var html = SLICKPLAN_HTML.replace('{title}', page.title);
            var $element = $(html).appendTo($summary);
            var percent = Math.round((_importIndex / _pages.length) * 100) + '%';
            $progress
                .find('.ui-progressbar-value').width(percent).end()
                .find('.progress-label').text(percent);
            $.post('<?php echo addslashes(str_replace('&amp;', '&', $form_action_url)); ?>', {
                slickplan: {
                    page: page.id,
                    parent: page.parent ? page.parent : '',
                    mlid: page.mlid ? page.mlid : 0,
                    last: (_pages && _pages[_importIndex + 1]) ? 0 : 1
                }
            }, function(data) {
                if (data && data.html) {
                    $element.replaceWith(data.html);
                    ++_importIndex;
                    if (data) {
                        if (data.mlid) {
                            _addMenuID(page.id, data.mlid);
                        }
                    }
                    if (_pages && _pages[_importIndex]) {
                        _importPage(_pages[_importIndex]);
                    } else {
                        var percent = '100%';
                        $progress
                            .find('.ui-progressbar-value').width(percent).end()
                            .find('.progress-label').text(percent);
                        $form.find('h3').text('Success!');
                        $form.find('.slickplan-show-summary').show();
                        $(window).scrollTop(0);
                        setTimeout(function() {
                            $progress.remove();
                        }, 500);
                    }
                }
            }, 'json');
        };

        var types = ['home', '1', 'util', 'foot'];
        for (var i = 0; i < types.length; ++i) {
            if (SLICKPLAN_JSON[types[i]] && SLICKPLAN_JSON[types[i]].length) {
                _generatePagesFlatArray(SLICKPLAN_JSON[types[i]]);
            }
        }

        $(window).load(function() {
            _importIndex = 0;
            if (_pages && _pages[_importIndex]) {
                $(window).scrollTop(0);
                _importPage(_pages[_importIndex]);
            }
        });
    });
</script>