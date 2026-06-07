/* global wp, jQuery */
jQuery( function ( $ ) {
    'use strict';

    // =========================================================
    // 1. GALLERIA — Media Library picker
    // =========================================================
    var galleryFrame = null;

    $( '#bsn-gallery-select-media' ).on( 'click', function ( e ) {
        e.preventDefault();

        if ( galleryFrame ) {
            galleryFrame.open();
            return;
        }

        galleryFrame = wp.media( {
            title:   'Seleziona immagini galleria',
            button:  { text: 'Inserisci immagini' },
            multiple: true,
            library:  { type: 'image' },
        } );

        galleryFrame.on( 'select', function () {
            var selection = galleryFrame.state().get( 'selection' );
            var urls = [];
            selection.each( function ( attachment ) {
                var url = attachment.get( 'url' );
                if ( url ) {
                    urls.push( url );
                }
            } );
            if ( urls.length === 0 ) {
                return;
            }
            var ta = document.getElementById( 'bsn-gallery-urls' );
            if ( ! ta ) {
                return;
            }
            var current = ( ta.value || '' ).trim();
            ta.value = current ? ( current + '\n' + urls.join( '\n' ) ) : urls.join( '\n' );
        } );

        galleryFrame.open();
    } );

    // =========================================================
    // 2. PRODOTTI CONSIGLIATI — Multi-select con ricerca
    // =========================================================
    var prodotti      = window.bsnProdottiDisponibili || [];
    var selectedIds   = [];

    function escHtml( text ) {
        return $( '<div>' ).text( String( text ) ).html();
    }

    function initConsigliati() {
        var raw = $( '#bsn-consigliati-ids-input' ).val() || '';
        try {
            selectedIds = JSON.parse( raw ) || [];
        } catch ( e ) {
            selectedIds = [];
        }
        selectedIds = selectedIds.map( function ( id ) {
            return parseInt( id, 10 );
        } ).filter( Boolean );
        renderPills();
    }

    function saveConsigliati() {
        $( '#bsn-consigliati-ids-input' ).val( JSON.stringify( selectedIds ) );
    }

    function renderPills() {
        var $pills = $( '#bsn-consigliati-pills' );
        $pills.empty();

        if ( selectedIds.length === 0 ) {
            $pills.append( '<span style="color:#888; font-style:italic; font-size:0.92em;">Nessun prodotto selezionato</span>' );
            return;
        }

        selectedIds.forEach( function ( id ) {
            var prod = prodotti.find( function ( p ) { return p.id === id; } );
            var label = prod ? prod.title : ( 'Prodotto #' + id );
            var $pill = $(
                '<span style="display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:#e8f0fe; border:1px solid #b0c8f5; border-radius:999px; font-size:0.88em;">' +
                escHtml( label ) +
                ' <button type="button" data-id="' + parseInt( id, 10 ) + '" aria-label="Rimuovi" style="background:none; border:none; cursor:pointer; padding:0; color:#555; font-size:1em; line-height:1;">&times;</button>' +
                '</span>'
            );
            $pills.append( $pill );
        } );
    }

    $( document ).on( 'click', '#bsn-consigliati-pills button[data-id]', function () {
        var id = parseInt( $( this ).data( 'id' ), 10 );
        selectedIds = selectedIds.filter( function ( x ) { return x !== id; } );
        renderPills();
        saveConsigliati();
    } );

    $( '#bsn-consigliati-search' ).on( 'input keyup', function () {
        var q        = $( this ).val().trim().toLowerCase();
        var $results = $( '#bsn-consigliati-results' );

        if ( ! q ) {
            $results.hide().empty();
            return;
        }

        var matches = prodotti.filter( function ( p ) {
            return p.title.toLowerCase().indexOf( q ) !== -1 &&
                   selectedIds.indexOf( p.id ) === -1;
        } ).slice( 0, 12 );

        if ( matches.length === 0 ) {
            $results.html( '<div style="padding:8px 12px; color:#888; font-size:0.92em;">Nessun risultato</div>' ).show();
            return;
        }

        var html = matches.map( function ( p ) {
            return '<div class="bsn-consigliati-item" data-id="' + p.id + '" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f0f0f0;">' +
                escHtml( p.title ) + '</div>';
        } ).join( '' );
        $results.html( html ).show();
    } );

    $( document ).on( 'mouseenter', '.bsn-consigliati-item', function () {
        $( this ).css( 'background', '#f0f6ff' );
    } ).on( 'mouseleave', '.bsn-consigliati-item', function () {
        $( this ).css( 'background', '' );
    } );

    $( document ).on( 'click', '.bsn-consigliati-item', function () {
        var id = parseInt( $( this ).data( 'id' ), 10 );
        if ( selectedIds.indexOf( id ) === -1 ) {
            selectedIds.push( id );
            renderPills();
            saveConsigliati();
        }
        $( '#bsn-consigliati-search' ).val( '' );
        $( '#bsn-consigliati-results' ).hide().empty();
    } );

    $( document ).on( 'click', function ( e ) {
        if ( ! $( e.target ).closest( '#bsn-consigliati-search, #bsn-consigliati-results' ).length ) {
            $( '#bsn-consigliati-results' ).hide();
        }
    } );

    initConsigliati();

    // =========================================================
    // 3. DOWNLOADS — Repeater dinamico
    // =========================================================
    var downloads = [];

    var tipoOptions = [
        { value: '',                label: 'Altro' },
        { value: 'manuale',         label: 'Manuale' },
        { value: 'scheda_tecnica',  label: 'Scheda tecnica' },
        { value: 'scheda_sicurezza',label: 'Scheda sicurezza' },
        { value: 'software',        label: 'Software' },
    ];

    function initDownloads() {
        var raw = $( '#bsn-downloads-input' ).val() || '';
        try {
            downloads = JSON.parse( raw ) || [];
        } catch ( e ) {
            downloads = [];
        }
        renderDownloads();
    }

    function saveDownloads() {
        $( '#bsn-downloads-input' ).val( JSON.stringify( downloads ) );
    }

    function buildTipoOptions( selected ) {
        return tipoOptions.map( function ( t ) {
            return '<option value="' + t.value + '"' +
                ( t.value === selected ? ' selected' : '' ) +
                '>' + t.label + '</option>';
        } ).join( '' );
    }

    function renderDownloads() {
        var $list = $( '#bsn-downloads-list' );
        $list.empty();

        if ( downloads.length === 0 ) {
            $list.append( '<p style="color:#888; font-style:italic; font-size:0.92em; margin:4px 0 8px;">Nessun download aggiunto.</p>' );
            return;
        }

        downloads.forEach( function ( item, idx ) {
            var $row = $(
                '<div class="bsn-download-row" data-idx="' + idx + '" style="display:flex; gap:8px; align-items:center; margin-bottom:8px; padding:10px; background:#f9fafb; border:1px solid #dde3ea; border-radius:6px; flex-wrap:wrap;">' +
                    '<input type="text" class="bsn-dl-titolo" placeholder="Titolo (es: Manuale d\'uso)" value="' + escHtml( item.titolo || '' ) + '" style="flex:2 1 160px;">' +
                    '<input type="text" class="bsn-dl-url" placeholder="URL o percorso file" value="' + escHtml( item.url || '' ) + '" style="flex:3 1 200px;">' +
                    '<button type="button" class="button bsn-dl-browse" title="Scegli dalla Media Library" style="flex-shrink:0;">Sfoglia</button>' +
                    '<select class="bsn-dl-tipo" style="flex:1 1 130px;">' + buildTipoOptions( item.tipo || '' ) + '</select>' +
                    '<button type="button" class="button button-link-delete bsn-dl-remove" style="flex-shrink:0; color:#b32d2e;">Rimuovi</button>' +
                '</div>'
            );
            $list.append( $row );
        } );
    }

    $( document ).on( 'input change', '#bsn-downloads-list .bsn-dl-titolo', function () {
        var idx = parseInt( $( this ).closest( '.bsn-download-row' ).data( 'idx' ), 10 );
        if ( downloads[ idx ] !== undefined ) {
            downloads[ idx ].titolo = $( this ).val();
            saveDownloads();
        }
    } );

    $( document ).on( 'input change', '#bsn-downloads-list .bsn-dl-url', function () {
        var idx = parseInt( $( this ).closest( '.bsn-download-row' ).data( 'idx' ), 10 );
        if ( downloads[ idx ] !== undefined ) {
            downloads[ idx ].url = $( this ).val();
            saveDownloads();
        }
    } );

    $( document ).on( 'change', '#bsn-downloads-list .bsn-dl-tipo', function () {
        var idx = parseInt( $( this ).closest( '.bsn-download-row' ).data( 'idx' ), 10 );
        if ( downloads[ idx ] !== undefined ) {
            downloads[ idx ].tipo = $( this ).val();
            saveDownloads();
        }
    } );

    $( document ).on( 'click', '#bsn-downloads-list .bsn-dl-remove', function () {
        var idx = parseInt( $( this ).closest( '.bsn-download-row' ).data( 'idx' ), 10 );
        downloads.splice( idx, 1 );
        renderDownloads();
        saveDownloads();
    } );

    $( document ).on( 'click', '#bsn-downloads-list .bsn-dl-browse', function () {
        var $urlInput = $( this ).siblings( '.bsn-dl-url' );
        var dlFrame   = wp.media( {
            title:    'Seleziona file',
            button:   { text: 'Usa questo file' },
            multiple: false,
        } );
        dlFrame.on( 'select', function () {
            var attachment = dlFrame.state().get( 'selection' ).first().toJSON();
            $urlInput.val( attachment.url ).trigger( 'change' );
        } );
        dlFrame.open();
    } );

    $( '#bsn-downloads-add' ).on( 'click', function () {
        downloads.push( { titolo: '', url: '', tipo: '' } );
        saveDownloads();
        renderDownloads();
    } );

    initDownloads();
} );
