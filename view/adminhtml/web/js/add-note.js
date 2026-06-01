define(['jquery', 'mage/translate'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element);
        var $list = $(config.listSelector);
        var $empty = $(config.emptySelector);
        var $btn = $root.find('button');
        var $textarea = $root.find('textarea[name="note_text"]');

        // Bind to the button click (not a form submit): the notes widget renders
        // inside the admin edit form, so it must not be a nested <form> element.
        $btn.on('click', function (e) {
            e.preventDefault();
            var noteText = ($textarea.val() || '').trim();
            if (noteText === '') {
                return;
            }
            $btn.prop('disabled', true);

            $.ajax({
                url: config.action,
                method: 'POST',
                data: {
                    form_key: $root.find('input[name="form_key"]').val(),
                    note_text: noteText
                },
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    alert(resp && resp.message ? resp.message : $.mage.__('Failed to add note.'));
                    return;
                }
                $empty.hide();
                if ($list.length === 0) {
                    $list = $('<ul class="mm-eu-w-notes-list"></ul>').insertAfter($root);
                }
                $list.prepend(renderNote(resp));
                $textarea.val('');
            }).fail(function () {
                alert($.mage.__('Network error while adding note.'));
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        function renderNote(data) {
            var $li = $('<li class="mm-eu-w-note-item"></li>');
            var $meta = $('<div class="mm-eu-w-note-meta"></div>');
            $meta.append($('<span class="mm-eu-w-note-when"></span>').text(formatDate(data.created_at)));
            $meta.append($('<span class="mm-eu-w-note-by"></span>').text($.mage.__(' by %1').replace('%1', data.actor)));
            var $text = $('<div class="mm-eu-w-note-text"></div>').text(data.note_text);
            $li.append($meta).append($text);
            return $li;
        }

        function formatDate(iso) {
            try {
                var d = new Date(iso.replace(' ', 'T') + 'Z');
                return d.toLocaleString();
            } catch (e) {
                return iso;
            }
        }
    };
});
