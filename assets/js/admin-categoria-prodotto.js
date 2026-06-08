/* global wp, jQuery, bsnCategoriaProdottoMedia */
jQuery(function ($) {
    'use strict';

    var frame = null;
    var mediaConfig = window.bsnCategoriaProdottoMedia || {};

    function setPreview($field, attachment) {
        var $input = $field.find('.bsn-categoria-image-id');
        var $preview = $field.find('.bsn-categoria-image-preview');
        var $remove = $field.find('.bsn-categoria-image-remove');
        var sizes = attachment.sizes || {};
        var thumb = sizes.thumbnail || sizes.medium || sizes.full || {};
        var url = thumb.url || attachment.url || '';

        $input.val(attachment.id || '');

        if (url) {
            $preview
                .addClass('has-image')
                .html('<img class="bsn-categoria-image-preview-img" src="' + url + '" alt="">');
        } else {
            $preview
                .removeClass('has-image')
                .text(mediaConfig.emptyText || 'Nessuna immagine selezionata');
        }

        $remove.toggle(Boolean(url));
    }

    $(document).on('click', '.bsn-categoria-image-select', function (e) {
        e.preventDefault();

        var $field = $(this).closest('.bsn-categoria-image-field');

        if (frame) {
            frame.off('select');
        }

        frame = wp.media({
            title: mediaConfig.title || 'Seleziona immagine categoria',
            button: { text: mediaConfig.buttonText || 'Usa questa immagine' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first();
            if (!attachment) {
                return;
            }

            setPreview($field, attachment.toJSON());
        });

        frame.open();
    });

    $(document).on('click', '.bsn-categoria-image-remove', function (e) {
        e.preventDefault();

        var $field = $(this).closest('.bsn-categoria-image-field');
        $field.find('.bsn-categoria-image-id').val('');
        $field.find('.bsn-categoria-image-preview')
            .removeClass('has-image')
            .text(mediaConfig.emptyText || 'Nessuna immagine selezionata');
        $(this).hide();
    });

    $(document).ajaxSuccess(function (event, xhr, settings) {
        if (!settings || typeof settings.data !== 'string') {
            return;
        }

        if (settings.data.indexOf('action=add-tag') === -1 || settings.data.indexOf('taxonomy=bs_categoria_prodotto') === -1) {
            return;
        }

        var $field = $('.bsn-categoria-image-field');
        $field.find('.bsn-categoria-image-id').val('');
        $field.find('.bsn-categoria-image-preview')
            .removeClass('has-image')
            .text(mediaConfig.emptyText || 'Nessuna immagine selezionata');
        $field.find('.bsn-categoria-image-remove').hide();
        $('#bsn_categoria_sottotitolo, #bsn_categoria_ordine').val('');
    });
});
