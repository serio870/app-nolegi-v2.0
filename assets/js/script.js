// Cache globale articoli (usata per ricerche e popup articoli)
var BSN_ARTICOLI_CACHE = [];

// Cache globale clienti (se ti serve anche fuori dal ready, lasciala qui, altrimenti la puoi mettere dentro)
var BSN_CLIENTI_CACHE = [];

// Cache KIT
var BSN_KITS_CACHE = [];
var BSN_KIT_MAP_BY_ARTICOLO = {};
var BSN_NOLEGGIO_KITS = [];
var BSN_KIT_INSTANCE_COUNTER = 0;
var BSN_KIT_AVAILABILITY_MAP = {};

var BSN_KIT_AVAILABILITY_TIMER = null;

function bsnFormatOrigineLabel(origine) {
    var normalized = (origine || '').toString().toLowerCase();

    if (normalized === 'frontend') {
        return {
            label: 'Sito pubblico',
            icon: '&#127760;',
            highlighted: true
        };
    }

    if (normalized === 'cerebro') {
        return {
            label: 'Cerebro',
            icon: '&#129302;',
            highlighted: true
        };
    }

    return {
        label: 'Gestionale interno',
        icon: '',
        highlighted: false
    };
}

jQuery(document).ready(function($) {
    function bsnFormatDateInputDisplay(value) {
        var parts;
        value = String(value || '');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return 'gg-mm-aaaa';
        }
        parts = value.split('-');
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }

    function bsnEnhanceOperatorDateInputs() {
        $('#bsn-form-noleggio input[type="date"]').each(function() {
            var $input = $(this);
            var $display;
            var $shell;
            var openingPicker = false;

            if ($input.data('bsnDateDisplayInit')) {
                return;
            }

            $input.data('bsnDateDisplayInit', '1');
            $input.addClass('bsn-admin-date-native');
            $input.wrap('<span class="bsn-admin-date-shell"></span>');
            $shell = $input.closest('.bsn-admin-date-shell');
            $display = $('<span class="bsn-admin-date-display" aria-hidden="true"></span>');
            $input.after($display);

            function syncDisplay() {
                var formatted = bsnFormatDateInputDisplay($input.val());
                $display.text(formatted);
                $display.toggleClass('is-placeholder', formatted === 'gg-mm-aaaa');
            }

            $input.on('change input', syncDisplay);
            $shell.on('click', function(event) {
                if (openingPicker) {
                    return;
                }
                if ($input.prop('disabled')) {
                    return;
                }

                event.preventDefault();
                try {
                    if (typeof $input[0].showPicker === 'function') {
                        $input[0].showPicker();
                        return;
                    }
                } catch (error) {
                }

                openingPicker = true;
                try {
                    $input[0].focus();
                    $input[0].click();
                } finally {
                    window.setTimeout(function() {
                        openingPicker = false;
                    }, 0);
                }
            });
            syncDisplay();
        });
    }

    bsnEnhanceOperatorDateInputs();

    // ==== TABS ====
    $('.tab-btn').on('click', function() {
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');

        $('.tab-content').removeClass('active');
        $('#' + $(this).data('tab')).addClass('active');

        if ($(this).data('tab') === 'calendario' && typeof bsnRenderCalendar === 'function') {
            bsnRenderCalendar();
        }

        bsnEnhanceOperatorDateInputs();
    });

    // Utility per formattare i correlati nella tabella articoli
    function bsnFormattaCorrelati(correlati_json) {
        try {
            var correlati = JSON.parse(correlati_json);
            if (!Array.isArray(correlati) || correlati.length === 0) return '-';

            var testo = correlati.map(function(c) {
                return c.qty + 'x ' + c.nome;
            }).join(', ');

            return testo.length > 30 ? testo.substring(0, 30) + '...' : testo;
        } catch (e) {
            return 'JSON errore';
        }
    }

    // Cache per ricerca nel form noleggi
    // (QUI NON ridichiarare con var, usiamo le globali)
    BSN_CLIENTI_CACHE = [];
    BSN_ARTICOLI_CACHE = [];

    var bsnListState = {
        clienti: { search: '', limit: 12, debounce: null },
        articoli: { search: '', limit: 12, page: 1, total: 0, totalPages: 1, debounce: null },
        etichette: { search: '', limit: 12, onlyUnprinted: 0, debounce: null, selected: {}, copies: {} },
        noleggi: { search: '', limit: 12, stato: '', debounce: null }
    };

    function bsnDebounce(fn, wait, bucket, key) {
        if (bucket[key]) {
            clearTimeout(bucket[key]);
        }
        bucket[key] = setTimeout(fn, wait);
    }

    function bsnMergeClientiCache(items, replaceAll) {
        var map = {};

        if (!replaceAll) {
            (BSN_CLIENTI_CACHE || []).forEach(function(cliente) {
                if (cliente && cliente.id) {
                    map[parseInt(cliente.id, 10)] = cliente;
                }
            });
        }

        (items || []).forEach(function(cliente) {
            if (!cliente || !cliente.id) {
                return;
            }

            var id = parseInt(cliente.id, 10);
            var existing = map[id] || {};
            map[id] = $.extend({}, existing, cliente);
        });

        BSN_CLIENTI_CACHE = Object.keys(map)
            .map(function(id) {
                return map[id];
            })
            .sort(function(a, b) {
                return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0);
            });
    }


    /* ==== CLIENTI ==== */

    // Submit form cliente (create / future update)
    $('#bsn-form-cliente').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);

        var dati = {
            cliente_id:         $form.find('input[name="cliente_id"]').val(),
            nome:               $form.find('input[name="nome"]').val(),
            cf_piva:            $form.find('input[name="cf_piva"]').val(),
            telefono:           $form.find('input[name="telefono"]').val(),
            indirizzo:          $form.find('input[name="indirizzo"]').val(),
            cap:                $form.find('input[name="cap"]').val(),
            citta:              $form.find('input[name="citta"]').val(),
            email:              $form.find('input[name="email"]').val(),
            categoria_cliente:  $form.find('select[name="categoria_cliente"]').val(),
            regime_percentuale: $form.find('input[name="regime_percentuale"]').val(),
            regime_note:        $form.find('input[name="regime_note"]').val(),
            doc_fronte:         $form.find('input[name="doc_fronte"]').val(),
            doc_retro:          $form.find('input[name="doc_retro"]').val(),
            // NUOVO:
            tipo_documento:     $form.find('select[name="tipo_documento"]').val(),
            numero_documento:   $form.find('input[name="numero_documento"]').val(),
            note:               $form.find('textarea[name="note"]').val()
        };


        if (!dati.nome) {
            alert('Inserisci il nome / ragione sociale.');
            return;
        }
        if (!dati.cf_piva) {
            alert('Inserisci CF / P.IVA.');
            return;
        }
        if (!dati.telefono) {
            alert('Inserisci il telefono.');
            return;
        }
        if (!dati.email) {
            alert('Inserisci la email.');
            return;
        }

        // Decide se creare o aggiornare
        var urlEndpoint = BSN_API.root + 'clienti';
        var azione      = 'creato';

        if (dati.cliente_id) {
            urlEndpoint = BSN_API.root + 'clienti/update';
            azione      = 'aggiornato';
        }

        $.ajax({
            url: urlEndpoint,
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: dati,
            success: function(response) {
                alert('Cliente ' + azione + ' correttamente.');
                $form[0].reset();
                $('#bsn-cliente-id').val('');
                $('#bsn-doc-fronte-preview').empty();
                $('#bsn-doc-retro-preview').empty();
                bsnCaricaClienti();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nel salvataggio cliente.');
            }
        });
    });

    function bsnRenderClienti(lista) {
        var wrapper = $('#bsn-lista-clienti');
        wrapper.empty();

        if (!lista || !lista.length) {
            wrapper.html('<p>Nessun cliente salvato.</p>');
            return;
        }

        var html = '' +
            '<table class="bsn-tabella" id="bsn-clienti-table">' +
                '<thead><tr>' +
                    '<th>ID</th>' +
                    '<th>Nome</th>' +
                    '<th>CF/P.IVA</th>' +
                    '<th>Telefono</th>' +
                    '<th>Email</th>' +
                    '<th>Categoria</th>' +
                    '<th>Azioni</th>' +
                '</tr></thead>' +
                '<tbody>';

        lista.forEach(function(c) {
            html += '' +
                '<tr class="bsn-cliente-row" data-id="' + c.id + '">' +
                    '<td>' + c.id + '</td>' +
                    '<td>' + (c.nome || '') + '</td>' +
                    '<td>' + (c.cf_piva || '') + '</td>' +
                    '<td>' + (c.telefono || '') + '</td>' +
                    '<td>' + (c.email || '') + '</td>' +
                    '<td>' + (c.categoria_cliente || '') + '</td>' +
                    '<td><div class="bsn-azioni-grid">' +
                        '<button type="button" class="btn bsn-cliente-details">🔍Dettagli</button>' +
                        '<button type="button" class="btn bsn-cliente-edit">✏️Modifica</button>' +
                        '<button type="button" class="btn bsn-cliente-delete">🗑️Elimina</button>' +
                        '<button type="button" class="btn bsn-cliente-modalita" style="background:#1a6fcf;color:#fff;">👤Mod. cliente</button>' +
                    '</div></td>' +
                '</tr>' +
                '<tr class="bsn-cliente-row-details" data-id="' + c.id + '" style="display:none;">' +
                    '<td colspan="7"><div class="bsn-cliente-details-container"></div></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrapper.html(html);
    }

    function bsnCaricaClienti() {
        var params = {
            limit: bsnListState.clienti.limit
        };
        if (bsnListState.clienti.search) {
            params.search = bsnListState.clienti.search;
        }

        $.ajax({
            url: BSN_API.root + 'clienti',
            method: 'GET',
            data: params,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                bsnMergeClientiCache(response.clienti || [], true);
                bsnRenderClienti(BSN_CLIENTI_CACHE);
            },
            error: function(err) {
                console.error(err);
                $('#bsn-lista-clienti').html('<p>Errore nel caricare i clienti.</p>');
            }
        });
    }

    $(document).on('input', '#bsn-clienti-search', function() {
        bsnListState.clienti.search = ($(this).val() || '').trim();
        bsnDebounce(function() {
            bsnCaricaClienti();
        }, 320, bsnListState.clienti, 'debounce');
    });

    $(document).on('change', '#bsn-clienti-limit', function() {
        bsnListState.clienti.limit = parseInt($(this).val(), 10) || 12;
        bsnCaricaClienti();
    });

    // Ricerca live sugli articoli (server-side + debounce)
    $(document).on('input', '#bsn-articoli-search', function() {
        bsnListState.articoli.search = ($(this).val() || '').trim();
        bsnListState.articoli.page = 1;
        bsnDebounce(function() {
            bsnCaricaArticoli();
        }, 320, bsnListState.articoli, 'debounce');
    });

    $(document).on('change', '#bsn-articoli-limit', function() {
        bsnListState.articoli.limit = parseInt($(this).val(), 10) || 12;
        bsnListState.articoli.page = 1;
        bsnCaricaArticoli();
    });
    
    // Dettagli cliente
    $(document).on('click', '.bsn-cliente-details', function() {
        var $row = $(this).closest('.bsn-cliente-row');
        var id   = $row.data('id');
        var $detailsTr = $('.bsn-cliente-row-details[data-id="' + id + '"]');
        var $container = $detailsTr.find('.bsn-cliente-details-container');

        if (!id) return;

        if ($detailsTr.is(':visible')) {
            $detailsTr.hide();
            return;
        }
        $detailsTr.show();

        // Recupera cliente dalla cache
        var cliente = BSN_CLIENTI_CACHE.find(function(c){ return parseInt(c.id,10) === parseInt(id,10); });
        if (!cliente) {
            $container.html('<em>Dati cliente non trovati.</em>');
            return;
        }

        var html = '';
        html += '<div><strong>Nome:</strong> ' + (cliente.nome || '') + '</div>';
        html += '<div><strong>Telefono:</strong> ' + (cliente.telefono || '-') + '</div>';
        html += '<div><strong>Email:</strong> ' + (cliente.email || '-') + '</div>';

        if (cliente.indirizzo || cliente.cap || cliente.citta) {
            html += '<div><strong>Indirizzo:</strong> ' +
                (cliente.indirizzo || '') +
                '</div>';
        }

        if (cliente.categoria_cliente) {
            html += '<div><strong>Categoria:</strong> ' + cliente.categoria_cliente + '</div>';
        }

        // Regime fiscale: percentuale + note, se presenti
        if (cliente.regime_percentuale !== null && cliente.regime_percentuale !== undefined) {
            html += '<div><strong>Regime fiscale:</strong> ' +
                cliente.regime_percentuale + '%' +
                (cliente.regime_note ? ' (' + cliente.regime_note + ')' : '') +
                '</div>';
        } else if (cliente.regime_note) {
            html += '<div><strong>Regime fiscale:</strong> ' + cliente.regime_note + '</div>';
        }

        if (cliente.note) {
            html += '<div style="margin-top:5px;"><strong>Note:</strong><br>' +
                cliente.note.replace(/\n/g, '<br>') +
                '</div>';
        }

        // Documenti
        if (cliente.doc_fronte || cliente.doc_retro || cliente.tipo_documento || cliente.numero_documento) {
            html += '<div style="margin-top:5px;"><strong>Documento:</strong><br>';

            // Riga tipo + numero
            if (cliente.tipo_documento || cliente.numero_documento) {
                var tipoLabel = '';
                switch (cliente.tipo_documento) {
                    case 'carta_identita':
                        tipoLabel = 'Carta d\'Identità';
                        break;
                    case 'patente':
                        tipoLabel = 'Patente di guida';
                        break;
                    case 'passaporto':
                        tipoLabel = 'Passaporto';
                        break;
                    case 'permesso_soggiorno':
                        tipoLabel = 'Permesso di soggiorno';
                        break;
                    default:
                        tipoLabel = cliente.tipo_documento || '';
                        break;
                }

                html += '<div style="margin-bottom:4px;">';
                if (tipoLabel) {
                    html += '<strong>Tipo:</strong> ' + tipoLabel;
                }
                if (cliente.numero_documento) {
                    html += (tipoLabel ? ' – ' : '') + '<strong>Numero:</strong> ' + cliente.numero_documento;
                }
                html += '</div>';
            }

            // Immagini fronte/retro
            if (cliente.doc_fronte) {
                html += '<img src="' + cliente.doc_fronte + '" alt="Documento fronte" style="max-width:160px; margin-right:10px;">';
            }
            if (cliente.doc_retro) {
                html += '<img src="' + cliente.doc_retro + '" alt="Documento retro" style="max-width:160px;">';
            }

            html += '</div>';
        }

        $container.html(html);
        $container.html(
            $container.html()
                .replace(/Carta d'IdentitÃ /g, "Carta d'Identita")
                .replace(/ â€“ /g, ' - ')
        );
        $container.html(
            $container.html()
                .replace(/Carta d'IdentitÃ /g, "Carta d'Identita")
                .replace(/ â€“ /g, ' - ')
        );
        return;

        if (typeof art !== 'undefined' && art && art.prodotto_pubblico_titolo) {
            $container.append('<div style="margin-top:5px;"><strong>Prodotto pubblico collegato:</strong> ' + art.prodotto_pubblico_titolo + '</div>');
        }

        var logisticParts = [];
        if (typeof art !== 'undefined' && art && art.prodotto_pubblico_sede_operativa) {
            logisticParts.push('<strong>Sede:</strong> ' + art.prodotto_pubblico_sede_operativa);
        }
        if (typeof art !== 'undefined' && art && art.prodotto_pubblico_veicolo_minimo) {
            logisticParts.push('<strong>Veicolo:</strong> ' + bsnGetVeicoloMinimoLabel(art.prodotto_pubblico_veicolo_minimo));
        }
        if (typeof art !== 'undefined' && art && (art.prodotto_pubblico_larghezza_cm || art.prodotto_pubblico_altezza_cm || art.prodotto_pubblico_profondita_cm)) {
            logisticParts.push('<strong>Dimensioni:</strong> L ' + (art.prodotto_pubblico_larghezza_cm || '-') + ' cm, H ' + (art.prodotto_pubblico_altezza_cm || '-') + ' cm, P ' + (art.prodotto_pubblico_profondita_cm || '-') + ' cm');
        }
        if (typeof art !== 'undefined' && art && art.prodotto_pubblico_peso_kg) {
            logisticParts.push('<strong>Peso:</strong> ' + art.prodotto_pubblico_peso_kg + ' kg');
        }
        if (logisticParts.length) {
            $container.append('<div style="margin-top:5px;"><strong>Dati canonici prodotto pubblico:</strong><br>' + logisticParts.join('<br>') + '</div>');
        }

        if (typeof art !== 'undefined' && art && art.note_logistiche) {
            $container.append('<div style="margin-top:5px;"><strong>Note logistiche interne:</strong><br>' + art.note_logistiche.replace(/\n/g, '<br>') + '</div>');
        }

        $container.html(
            $container.html()
                .replace(/QuantitÃ  disponibile:/g, 'Quantita disponibile:')
                .replace(/â‚¬/g, 'EUR')
                .replace(/â€“/g, '-')
        );

        $container.html(
            $container.html()
                .replace(/QuantitÃ  disponibile:/g, 'Quantita disponibile:')
                .replace(/â‚¬/g, 'EUR')
                .replace(/â€“/g, '-')
        );
    });

    // Dettagli articolo
    $(document).on('click', '.bsn-articolo-details', function() {
        var $row      = $(this).closest('.bsn-articolo-row');
        var id        = $row.data('id');
        var $detailsTr = $('.bsn-articolo-row-details[data-id="' + id + '"]');
        var $container = $detailsTr.find('.bsn-articolo-details-container');

        if (!id) return;

        // toggle base
        if ($detailsTr.is(':visible')) {
            $detailsTr.hide();
            return;
        }
        $detailsTr.show();

        // recupera articolo dalla cache
        var art = BSN_ARTICOLI_CACHE.find(function(a) {
            return parseInt(a.id, 10) === parseInt(id, 10);
        });

        if (!art) {
            $container.html('<em>Dati articolo non trovati.</em>');
            return;
        }

        var html = '';

        html += '<div><strong>Nome:</strong> ' + (art.nome || '') + '</div>';
        html += '<div><strong>Codice:</strong> ' + (art.codice || '') + '</div>';

        html += '<div><strong>Prezzo/giorno:</strong> ' +
            (art.prezzo_giorno || '0') + ' €</div>';

        html += '<div><strong>Valore del bene:</strong> ' +
            (art.valore_bene || '0') + ' €</div>';

        html += '<div><strong>Quantità disponibile:</strong> ' +
            (art.qty_disponibile || '0') + '</div>';

        if (art.ubicazione) {
            html += '<div><strong>Ubicazione:</strong> ' +
                art.ubicazione + '</div>';
        }

        // Sconti
        html += '<div style="margin-top:5px;"><strong>Sconti per categoria:</strong><br>' +
            'Standard: ' + (art.sconto_standard || 0) + '% – ' +
            'Fidato: ' + (art.sconto_fidato || 0) + '% – ' +
            'Premium: ' + (art.sconto_premium || 0) + '% – ' +
            'Service: ' + (art.sconto_service || 0) + '% – ' +
            'Collaboratori: ' + (art.sconto_collaboratori || 0) + '%' +
            '</div>';

        if (parseInt(art.qty_discount_enabled, 10) === 1) {
            html += '<div style="margin-top:5px;"><strong>Sconto quantita lineare:</strong><br>' +
                'Da qty ' + (art.qty_discount_start_qty || 0) +
                ' a qty ' + (art.qty_discount_max_qty || art.qty_disponibile || '-') +
                ' fino a -' + (art.qty_discount_max_pct || 0) + '%' +
                '</div>';
        }

        // Correlati dettagliati
        if (art.correlati) {
            try {
                var corr = JSON.parse(art.correlati);
                if (Array.isArray(corr) && corr.length) {
                    html += '<div style="margin-top:5px;"><strong>Correlati:</strong><ul style="margin:3px 0 0 18px; padding:0;">';
                    corr.forEach(function(c) {
                        var q = c.qty || 1;
                        var n = c.nome || '';
                        html += '<li>' + q + 'x ' + n + '</li>';
                    });
                    html += '</ul></div>';
                }
            } catch (e) {}
        }

        if (art.note) {
            html += '<div style="margin-top:5px;"><strong>Note:</strong><br>' +
                art.note.replace(/\n/g, '<br>') +
                '</div>';
        }

        $container.html(html);
        $container.append('<div style="margin-top:5px;"><strong>Modalita inventario:</strong> ' + bsnGetArticoloInventoryModeLabel(art.inventory_mode || 'seriale') + '</div>');
        $container.append('<div><strong>Quantita minima noleggiabile:</strong> ' + (art.min_qty || 1) + '</div>');

        if (art.prodotto_pubblico_titolo) {
            $container.append('<div style="margin-top:5px;"><strong>Prodotto pubblico collegato:</strong> ' + art.prodotto_pubblico_titolo + '</div>');
        }

        var articoloLogisticParts = [];
        if (art.prodotto_pubblico_sede_operativa) {
            articoloLogisticParts.push('<strong>Sede:</strong> ' + art.prodotto_pubblico_sede_operativa);
        }
        if (art.prodotto_pubblico_veicolo_minimo) {
            articoloLogisticParts.push('<strong>Veicolo:</strong> ' + bsnGetVeicoloMinimoLabel(art.prodotto_pubblico_veicolo_minimo));
        }
        if (art.prodotto_pubblico_larghezza_cm || art.prodotto_pubblico_altezza_cm || art.prodotto_pubblico_profondita_cm) {
            articoloLogisticParts.push('<strong>Dimensioni:</strong> L ' + (art.prodotto_pubblico_larghezza_cm || '-') + ' cm, H ' + (art.prodotto_pubblico_altezza_cm || '-') + ' cm, P ' + (art.prodotto_pubblico_profondita_cm || '-') + ' cm');
        }
        if (art.prodotto_pubblico_peso_kg) {
            articoloLogisticParts.push('<strong>Peso:</strong> ' + art.prodotto_pubblico_peso_kg + ' kg');
        }
        if (articoloLogisticParts.length) {
            $container.append('<div style="margin-top:5px;"><strong>Dati canonici prodotto pubblico:</strong><br>' + articoloLogisticParts.join('<br>') + '</div>');
        }

        if (art.note_logistiche) {
            $container.append('<div style="margin-top:5px;"><strong>Note logistiche interne:</strong><br>' + art.note_logistiche.replace(/\n/g, '<br>') + '</div>');
        }

        $container.html(
            $container.html()
                .replace(/QuantitÃ  disponibile:/g, 'Quantita disponibile:')
                .replace(/â‚¬/g, 'EUR')
                .replace(/â€“/g, '-')
        );

        $container.html(
            $container.html()
                .replace(/QuantitÃ  disponibile:/g, 'Quantita disponibile:')
                .replace(/â‚¬/g, 'EUR')
                .replace(/â€“/g, '-')
        );
    });

    // Modifica cliente: carica dati nel form
    $(document).on('click', '.bsn-cliente-edit', function() {
        var $row = $(this).closest('.bsn-cliente-row');
        var id   = $row.data('id');

        if (!id) return;

        var cliente = BSN_CLIENTI_CACHE.find(function(c){ return parseInt(c.id,10) === parseInt(id,10); });
        if (!cliente) {
            alert('Dati cliente non trovati.');
            return;
        }

        var $form = $('#bsn-form-cliente');

        $('#bsn-cliente-id').val(cliente.id);
        $form.find('input[name="nome"]').val(cliente.nome || '');
        $form.find('input[name="cognome"]').val(cliente.cognome || '');
        $form.find('input[name="cf_piva"]').val(cliente.cf_piva || '');
        $form.find('input[name="telefono"]').val(cliente.telefono || '');
        $form.find('input[name="indirizzo"]').val(cliente.indirizzo || '');
        $form.find('input[name="cap"]').val(cliente.cap || '');
        $form.find('input[name="citta"]').val(cliente.citta || '');
        $form.find('input[name="email"]').val(cliente.email || '');
        $form.find('select[name="categoria_cliente"]').val(cliente.categoria_cliente || 'standard');
        $form.find('input[name="regime_percentuale"]').val(cliente.regime_percentuale || 22.00);
        $form.find('input[name="regime_note"]').val(cliente.regime_note || '');
        $form.find('textarea[name="note"]').val(cliente.note || '');
        // NUOVO: Tipo e numero documento
        $form.find('select[name="tipo_documento"]').val(cliente.tipo_documento || '');
        $form.find('input[name="numero_documento"]').val(cliente.numero_documento || '');
        // Documenti
        $('#bsn-doc-fronte-path').val(cliente.doc_fronte || '');
        $('#bsn-doc-retro-path').val(cliente.doc_retro || '');

        var $prevF = $('#bsn-doc-fronte-preview').empty();
        var $prevR = $('#bsn-doc-retro-preview').empty();
        if (cliente.doc_fronte) {
            $prevF.html('<img src="' + cliente.doc_fronte + '" alt="Documento fronte" style="max-width:160px;">');
        }
        if (cliente.doc_retro) {
            $prevR.html('<img src="' + cliente.doc_retro + '" alt="Documento retro" style="max-width:160px;">');
        }

        $('html, body').animate({
            scrollTop: $('#clienti').offset().top - 20
        }, 300);
    });

    // Elimina cliente
    $(document).on('click', '.bsn-cliente-delete', function() {
        var $row = $(this).closest('.bsn-cliente-row');
        var id   = $row.data('id');

        if (!id) return;

        if (!confirm('Vuoi eliminare definitivamente questo cliente?')) {
            return;
        }

        $.ajax({
            url: BSN_API.root + 'clienti/delete',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: { id: id },
            success: function(response) {
                alert('Cliente eliminato.');
                bsnCaricaClienti();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nell\'eliminazione cliente.');
            }
        });
    });

    // Modalità cliente: entra nel frontend come il cliente selezionato
    $(document).on('click', '.bsn-cliente-modalita', function() {
        var $row      = $(this).closest('.bsn-cliente-row');
        var id        = $row.data('id');
        var nomeCell  = $row.find('td').eq(1).text().trim();
        var nome      = nomeCell || ('Cliente #' + id);

        if (!id) return;

        if (!confirm('Vuoi entrare in Modalità cliente per ' + nome + '?\n\nLe azioni di carrello e preventivo saranno associate a questo cliente.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Avvio...');

        fetch(BSN_API.root + 'modalita-cliente/avvia', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': BSN_API.nonce
            },
            credentials: 'same-origin',
            body: JSON.stringify({ cliente_id: parseInt(id, 10) })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.href = data.redirect_url;
            } else {
                alert('Errore: ' + (data.message || 'Impossibile avviare la modalità cliente.'));
                $btn.prop('disabled', false).text('👤Modalità cliente');
            }
        })
        .catch(function(err) {
            console.error(err);
            alert('Errore di rete. Riprova.');
            $btn.prop('disabled', false).text('👤Modalità cliente');
        });
    });

    // Upload documento FRONTE
    $(document).on('change', '#bsn-doc-fronte-file', function() {
        var file = this.files[0];
        if (!file) return;

        var formData = new FormData();
        formData.append('file', file);

        $.ajax({
            url: BSN_API.root + 'upload-doc',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                if (response && response.url) {
                    $('#bsn-doc-fronte-path').val(response.url);
                    $('#bsn-doc-fronte-preview').html(
                        '<img src="' + response.url + '" alt="Documento fronte" style="max-width:160px;">'
                    );
                } else {
                    alert('Upload documento fronte riuscito ma URL non trovato.');
                }
            },
            error: function(err) {
                console.error(err);
                alert('Errore nell\'upload del documento fronte.');
            }
        });
    });

    // Upload documento RETRO
    $(document).on('change', '#bsn-doc-retro-file', function() {
        var file = this.files[0];
        if (!file) return;

        var formData = new FormData();
        formData.append('file', file);

        $.ajax({
            url: BSN_API.root + 'upload-doc',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                if (response && response.url) {
                    $('#bsn-doc-retro-path').val(response.url);
                    $('#bsn-doc-retro-preview').html(
                        '<img src="' + response.url + '" alt="Documento retro" style="max-width:160px;">'
                    );
                } else {
                    alert('Upload documento retro riuscito ma URL non trovato.');
                }
            },
            error: function(err) {
                console.error(err);
                alert('Errore nell\'upload del documento retro.');
            }
        });
    });

    /* ==== ARTICOLI ==== */

    // Gestione UI correlati (aggiungi/rimuovi righe)
    $('#bsn-correlato-add').on('click', function() {
        var $wrapper = $('#bsn-correlati-wrapper');
        var $row = $(
            '<div class="bsn-correlato-row">' +
                '<input type="text" name="correlato_nome[]" placeholder="Nome correlato">' +
                '<input type="number" name="correlato_qty[]" placeholder="Q.tà" min="1" value="1" style="max-width:80px;">' +
                '<button type="button" class="btn bsn-correlato-remove">X</button>' +
            '</div>'
        );
        $wrapper.append($row);
    });

    $(document).on('click', '.bsn-correlato-remove', function() {
        $(this).closest('.bsn-correlato-row').remove();
    });

    // Autogenerazione codice da nome (se codice vuoto)
    $(document).on('blur', '#bsn-form-articolo input[name="nome"]', function() {
        var nome = $(this).val().trim();
        var $codice = $('#bsn-form-articolo input[name="codice"]');
        var codiceAttuale = $codice.val().trim();

        if (!nome || codiceAttuale) {
            return;
        }

        var base = nome.replace(/\s+/g, '').toUpperCase();
        $codice.val(base + '#1');
    });

    // Submit form articolo (create / update con correlati)
    $('#bsn-form-articolo').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);

        var dati = {
            nome:           $form.find('input[name="nome"]').val(),
            codice:         $form.find('input[name="codice"]').val(),
            prezzo_giorno:  $form.find('input[name="prezzo_giorno"]').val(),
            valore_bene:    $form.find('input[name="valore_bene"]').val(),

            sconto_standard:      $form.find('input[name="sconto_standard"]').val(),
            sconto_fidato:        $form.find('input[name="sconto_fidato"]').val(),
            sconto_premium:       $form.find('input[name="sconto_premium"]').val(),
            sconto_service:       $form.find('input[name="sconto_service"]').val(),
            sconto_collaboratori: $form.find('input[name="sconto_collaboratori"]').val(),
            qty_discount_enabled: $form.find('input[name="qty_discount_enabled"]').is(':checked') ? 1 : 0,
            qty_discount_start_qty: $form.find('input[name="qty_discount_start_qty"]').val(),
            qty_discount_max_qty: $form.find('input[name="qty_discount_max_qty"]').val(),
            qty_discount_max_pct: $form.find('input[name="qty_discount_max_pct"]').val(),

            noleggio_scalare: $form.find('input[name="noleggio_scalare"]').is(':checked') ? 1 : 0,

           qty_disponibile: $form.find('input[name="qty_disponibile"]').val(),
            inventory_mode: $form.find('select[name="inventory_mode"]').val(),
            prodotto_pubblico_id: $form.find('input[name="prodotto_pubblico_id"]').val(),
            min_qty:         $form.find('input[name="min_qty"]').val(),
            stato_utilizzabilita: $form.find('select[name="stato_utilizzabilita"]').val(),
            ubicazione:      $form.find('input[name="ubicazione"]').val(),
            note:            $form.find('textarea[name="note"]').val(),
            note_logistiche: $form.find('textarea[name="note_logistiche"]').val(),
            external_product_url:  $form.find('input[name="external_product_url"]').val(),
            external_product_slug: $form.find('input[name="external_product_slug"]').val(),
            external_product_name: $form.find('input[name="external_product_name"]').val(),
            external_image_url:    $form.find('input[name="external_image_url"]').val(),
            external_last_sync:    $form.find('input[name="external_last_sync"]').val()
        };

        if (!((dati.external_product_url || '').trim())) {
            dati.external_product_slug = '';
            dati.external_product_name = '';
            dati.external_image_url = '';
            dati.external_last_sync = '';
        }

        // Costruisci array correlati da input multipli
        var correlati = [];
        $('#bsn-correlati-wrapper .bsn-correlato-row').each(function() {
            var nomeCorr = $(this).find('input[name="correlato_nome[]"]').val();
            var qtyCorr = $(this).find('input[name="correlato_qty[]"]').val();
            if (nomeCorr && qtyCorr > 0) {
                correlati.push({
                    nome: nomeCorr,
                    qty: parseInt(qtyCorr, 10) || 1
                });
            }
        });
        dati.correlati = JSON.stringify(correlati);

        if (!dati.nome || !dati.codice) {
            alert('Nome e codice articolo sono obbligatori.');
            return;
        }

        if ((parseInt(dati.min_qty, 10) || 0) < 1) {
            alert('La quantita minima noleggiabile deve essere almeno 1.');
            return;
        }

        if (parseInt(dati.qty_discount_enabled, 10) === 1) {
            var qtyDiscountStart = parseInt(dati.qty_discount_start_qty, 10) || 0;
            var qtyDiscountMax = dati.qty_discount_max_qty === '' ? 0 : (parseInt(dati.qty_discount_max_qty, 10) || 0);
            var qtyDiscountPct = parseFloat(String(dati.qty_discount_max_pct || '0').replace(',', '.')) || 0;

            if (qtyDiscountStart < 1) {
                alert('La quantita inizio sconto deve essere almeno 1.');
                return;
            }
            if (qtyDiscountMax > 0 && qtyDiscountMax < qtyDiscountStart) {
                alert('La quantita massima commerciale deve essere vuota oppure maggiore o uguale alla quantita inizio sconto.');
                return;
            }
            if (qtyDiscountPct < 0 || qtyDiscountPct > 100) {
                alert('Lo sconto massimo quantita deve essere compreso tra 0 e 100.');
                return;
            }
        }

        // Controlla se siamo in modalità UPDATE (modifica)
        var articoloId = $form.data('articolo-id');
        var urlEndpoint = BSN_API.root + 'articoli';
        var method = 'POST';
        var azione = 'creato';

        if (articoloId) {
            urlEndpoint = BSN_API.root + 'articoli';
            method = 'PUT';
            dati.id = articoloId;
            azione = 'aggiornato';
        }

        $.ajax({
            url: urlEndpoint,
            method: method,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: dati,
            success: function(response) {
                alert('Articolo ' + azione + ' correttamente.');

                // reset form
                $form[0].reset();
                $form.removeData('articolo-id');
                $form.find('select[name="inventory_mode"]').data('inventory-manual', 0);
                $('#bsn-articolo-external-preview').empty();
                bsnSetSelectedPublicProduct(null);

                // ricrea una riga correlato base
                $('#bsn-correlati-wrapper').html(
                    '<div class="bsn-correlato-row">' +
                        '<input type="text" name="correlato_nome[]" placeholder="Nome correlato">' +
                        '<input type="number" name="correlato_qty[]" placeholder="Q.tà" min="1" value="1" style="max-width:80px;">' +
                        '<button type="button" class="btn bsn-correlato-remove">X</button>' +
                    '</div>'
                );

                bsnApplyInventoryModeSuggestion(true);
                bsnUpdatePublicProductSuggestion();
                bsnCaricaArticoli();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nel salvataggio articolo.');
            }
        });
    });

    function bsnAggiornaAnteprimaProdotto(esterni) {
        var $preview = $('#bsn-articolo-external-preview');
        $preview.empty();

        if (!esterni) {
            return;
        }

        var nome = esterni.external_product_name || '';
        var url = esterni.external_product_url || '';
        var img = esterni.external_image_url || '';
        var label = '';

        if (img) {
            label += '<img src="' + img + '" alt="" style="width:50px; height:auto; margin-right:8px; vertical-align:middle;">';
        }
        if (nome) {
            if (url) {
                label += '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;">' + nome + '</a>';
            } else {
                label += '<span>' + nome + '</span>';
            }
        }

        if (label) {
            $preview.html(label);
        }
    }

    function bsnResetCampiProdottoEsterno($form) {
        $form.find('#bsn-articolo-external-slug').val('');
        $form.find('#bsn-articolo-external-name').val('');
        $form.find('#bsn-articolo-external-image').val('');
        $form.find('#bsn-articolo-external-sync').val('');
        $('#bsn-articolo-external-preview').empty();
    }

    function bsnGetArticoloInventoryModeLabel(value) {
        if (value === 'quantita') return 'A quantita';
        return 'Seriale / univoco';
    }

    function bsnGetArticoloStatusLabel(value) {
        if (value === 'problematico_utilizzabile') return 'Problematico ma utilizzabile';
        if (value === 'non_noleggiabile') return 'Non noleggiabile';
        if (value === 'manutenzione') return 'In manutenzione';
        return 'Disponibile';
    }

    function bsnGetVeicoloMinimoLabel(value) {
        var labels = {
            citycar: 'Citycar',
            berlina: 'Berlina',
            station_wagon: 'Station wagon',
            suv: 'SUV',
            furgone_piccolo: 'Furgone piccolo',
            furgone_medio: 'Furgone medio',
            furgone_grande: 'Furgone grande',
            camion: 'Camion'
        };
        return labels[value] || '';
    }

    function bsnApplyArticoliUiCleanup() {
        var $form = $('#bsn-form-articolo');
        if (!$form.length) return;

        $form.find('input[name="qty_disponibile"]').closest('div').find('label').first().text('Quantita disponibile');
        $form.find('select[name="inventory_mode"]').closest('div').find('label').first().text('Modalita inventario');
        $form.find('select[name="inventory_mode"]').closest('div').find('small').first().text('Suggerita in automatico da quantita disponibile, ma sempre modificabile.');
        $form.find('input[name="min_qty"]').closest('div').find('label').first().text('Quantita minima noleggiabile');
        $form.find('select[name="stato_utilizzabilita"]').closest('div').find('label').first().text('Stato base articolo');

        $form.find('select[name="veicolo_minimo"]').closest('div').hide();
        $form.find('input[name="larghezza_cm"]').closest('div').hide();
        $form.find('input[name="altezza_cm"]').closest('div').hide();
        $form.find('input[name="profondita_cm"]').closest('div').hide();
        $form.find('input[name="peso_kg"]').closest('div').hide();
    }

    function bsnGetPublicProductById(id) {
        id = parseInt(id, 10);
        if (!id || !Array.isArray(window.BSN_PUBLIC_PRODUCTS)) return null;
        for (var i = 0; i < window.BSN_PUBLIC_PRODUCTS.length; i++) {
            if (parseInt(window.BSN_PUBLIC_PRODUCTS[i].id, 10) === id) {
                return window.BSN_PUBLIC_PRODUCTS[i];
            }
        }
        return null;
    }

    function bsnRenderSelectedPublicProductLogistics(product) {
        var $box = $('#bsn-prodotto-pubblico-logistica');
        if (!$box.length) return;

        if (!product) {
            $box.html('Nessun prodotto pubblico collegato.');
            return;
        }

        var parts = [];
        if (product.sede_operativa) {
            parts.push('<strong>Sede:</strong> ' + product.sede_operativa);
        }
        if (product.veicolo_minimo) {
            parts.push('<strong>Veicolo:</strong> ' + bsnGetVeicoloMinimoLabel(product.veicolo_minimo));
        }
        if (product.larghezza_cm || product.altezza_cm || product.profondita_cm) {
            parts.push('<strong>Dimensioni:</strong> L ' + (product.larghezza_cm || '-') + ' cm, H ' + (product.altezza_cm || '-') + ' cm, P ' + (product.profondita_cm || '-') + ' cm');
        }
        if (product.peso_kg) {
            parts.push('<strong>Peso:</strong> ' + product.peso_kg + ' kg');
        }

        var html = '<div><strong>Prodotto collegato:</strong> ' + (product.title || ('Prodotto #' + product.id)) + '</div>';
        if (parts.length) {
            html += '<div style="margin-top:4px;">' + parts.join('<br>') + '</div>';
        } else {
            html += '<div style="margin-top:4px;">Nessun dato logistico canonico impostato sul prodotto pubblico.</div>';
        }
        $box.html(html);
    }

    function bsnSetSelectedPublicProduct(product) {
        var $form = $('#bsn-form-articolo');
        $form.find('input[name="prodotto_pubblico_id"]').val(product ? (product.id || '') : '');
        $('#bsn-prodotto-pubblico-search').val(product ? (product.title || '') : '');
        bsnResetSearchDropdownActive($('#bsn-prodotto-pubblico-risultati'), '.bsn-prodotto-pubblico-item');
        $('#bsn-prodotto-pubblico-risultati').hide().empty();
        bsnRenderSelectedPublicProductLogistics(product || null);
        bsnRenderPublicProductSuggestion(null);
    }

    function bsnNormalizePublicGroupKey(value) {
        value = (value || '').toString();
        value = value.replace(/#.*$/, '');
        value = value.normalize ? value.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : value;
        value = value.toUpperCase().replace(/[^A-Z0-9]+/g, '');
        return value.trim();
    }

    function bsnGetPublicProductMatchKeys(product) {
        if (!product) return [];
        var keys = [];
        if (Array.isArray(product.match_keys)) {
            product.match_keys.forEach(function(key) {
                key = bsnNormalizePublicGroupKey(key);
                if (key && keys.indexOf(key) === -1) keys.push(key);
            });
        }
        ['title', 'slug'].forEach(function(field) {
            var key = bsnNormalizePublicGroupKey(product[field] || '');
            if (key && keys.indexOf(key) === -1) keys.push(key);
        });
        return keys;
    }

    function bsnGetSuggestedPublicProductForForm() {
        var $form = $('#bsn-form-articolo');
        if (!$form.length) return null;
        if (parseInt($form.find('input[name="prodotto_pubblico_id"]').val(), 10) > 0) return null;
        if (!Array.isArray(window.BSN_PUBLIC_PRODUCTS)) return null;

        var keys = [];
        var codiceKey = bsnNormalizePublicGroupKey($form.find('input[name="codice"]').val() || '');
        var nomeKey = bsnNormalizePublicGroupKey($form.find('input[name="nome"]').val() || '');
        if (codiceKey) keys.push(codiceKey);
        if (nomeKey && keys.indexOf(nomeKey) === -1) keys.push(nomeKey);
        if (!keys.length) return null;

        var matches = [];
        window.BSN_PUBLIC_PRODUCTS.forEach(function(product) {
            var productKeys = bsnGetPublicProductMatchKeys(product);
            var isMatch = keys.some(function(key) {
                return productKeys.indexOf(key) !== -1;
            });
            if (isMatch) {
                matches.push(product);
            }
        });

        return matches.length === 1 ? matches[0] : null;
    }

    function bsnEnsurePublicProductSuggestionBox() {
        var $box = $('#bsn-prodotto-pubblico-suggerimento');
        if ($box.length) return $box;

        $box = $('<div id="bsn-prodotto-pubblico-suggerimento" style="display:none; margin-top:8px; padding:8px; border:1px dashed #c4ccd7; background:#fffdf5; border-radius:6px;"></div>');
        $('#bsn-prodotto-pubblico-logistica').after($box);
        return $box;
    }

    function bsnRenderPublicProductSuggestion(product) {
        var $box = bsnEnsurePublicProductSuggestionBox();
        if (!product) {
            $box.hide().empty();
            return;
        }

        var label = product.title || ('Prodotto #' + product.id);
        $box.html(
            '<strong>Suggerimento automatico:</strong> possibile collegamento con <strong>' + label + '</strong>.' +
            ' <button type="button" class="btn btn-secondary bsn-apply-public-product-suggestion" data-id="' + product.id + '" style="margin-left:8px;">Applica</button>'
        ).show();
    }

    function bsnFilterPublicProducts(term) {
        if (!Array.isArray(window.BSN_PUBLIC_PRODUCTS)) return [];
        term = (term || '').toLowerCase().trim();
        if (!term) return window.BSN_PUBLIC_PRODUCTS.slice(0, 15);

        return window.BSN_PUBLIC_PRODUCTS.filter(function(item) {
            return (item.title || '').toLowerCase().indexOf(term) !== -1 ||
                (item.slug || '').toLowerCase().indexOf(term) !== -1;
        }).slice(0, 20);
    }

    function bsnRenderPublicProductSearchResults(term) {
        var $box = $('#bsn-prodotto-pubblico-risultati');
        if (!$box.length) return;

        var items = bsnFilterPublicProducts(term);
        if (!items.length) {
            bsnResetSearchDropdownActive($box, '.bsn-prodotto-pubblico-item');
            $box.html('<div style="padding:8px; color:#666;">Nessun prodotto pubblico trovato.</div>').show();
            return;
        }

        var html = '';
        items.forEach(function(item) {
            html += '<div class="bsn-prodotto-pubblico-item" data-id="' + item.id + '" style="padding:8px; border-bottom:1px solid #eee; cursor:pointer;">' +
                '<strong>' + (item.title || ('Prodotto #' + item.id)) + '</strong>' +
                (item.slug ? '<div style="font-size:12px; color:#666;">' + item.slug + '</div>' : '') +
            '</div>';
        });

        $box.html(html).show();
        bsnSetSearchDropdownActive($box, '.bsn-prodotto-pubblico-item', 0);
    }

    function bsnUpdatePublicProductSuggestion() {
        bsnRenderPublicProductSuggestion(bsnGetSuggestedPublicProductForForm());
    }

    function bsnApplyInventoryModeSuggestion(force) {
        var $form = $('#bsn-form-articolo');
        var $mode = $form.find('select[name="inventory_mode"]');
        if (!$mode.length) return;
        if (!force && $mode.data('inventory-manual') === 1) return;

        var qty = parseInt($form.find('input[name="qty_disponibile"]').val(), 10);
        if (!isFinite(qty)) qty = 1;
        $mode.val(qty > 1 ? 'quantita' : 'seriale');
    }

    function bsnGetSearchDropdownItems($box, itemSelector) {
        if (!$box || !$box.length) return $();
        return $box.find(itemSelector);
    }

    function bsnResetSearchDropdownActive($box, itemSelector) {
        var $items = bsnGetSearchDropdownItems($box, itemSelector);
        $items.removeClass('bsn-search-active').css({
            background: '',
            outline: ''
        });
        if ($box && $box.length) {
            $box.removeData('bsnActiveIndex');
        }
    }

    function bsnEnsureSearchDropdownItemVisible($box, $item) {
        if (!$box || !$box.length || !$item || !$item.length) return;

        var boxTop = $box.scrollTop();
        var boxHeight = $box.innerHeight();
        var itemTop = $item.position().top + boxTop;
        var itemBottom = itemTop + $item.outerHeight();

        if (itemTop < boxTop) {
            $box.scrollTop(itemTop);
        } else if (itemBottom > boxTop + boxHeight) {
            $box.scrollTop(itemBottom - boxHeight);
        }
    }

    function bsnSetSearchDropdownActive($box, itemSelector, index) {
        var $items = bsnGetSearchDropdownItems($box, itemSelector);
        if (!$items.length) {
            bsnResetSearchDropdownActive($box, itemSelector);
            return $();
        }

        if (!isFinite(index)) {
            index = 0;
        }
        if (index < 0) {
            index = $items.length - 1;
        }
        if (index >= $items.length) {
            index = 0;
        }

        bsnResetSearchDropdownActive($box, itemSelector);

        var $active = $items.eq(index);
        $active.addClass('bsn-search-active').css({
            background: '#e8f2ff',
            outline: '1px solid #b8d3ff'
        });
        $box.data('bsnActiveIndex', index);
        bsnEnsureSearchDropdownItemVisible($box, $active);
        return $active;
    }

    function bsnHandleSearchDropdownKey(e, options) {
        var key = e.key || '';
        var keyCode = e.keyCode || 0;
        var isDown = key === 'ArrowDown' || keyCode === 40;
        var isUp = key === 'ArrowUp' || keyCode === 38;
        var isEnter = key === 'Enter' || keyCode === 13;
        var isEscape = key === 'Escape' || keyCode === 27;

        if (!isDown && !isUp && !isEnter && !isEscape) {
            return false;
        }

        var $box = options.box;
        var itemSelector = options.itemSelector;
        var $items = bsnGetSearchDropdownItems($box, itemSelector);

        if (isEscape) {
            e.preventDefault();
            bsnResetSearchDropdownActive($box, itemSelector);
            if ($box && $box.length) {
                $box.hide();
            }
            return true;
        }

        if (!$box || !$box.length || !$box.is(':visible') || !$items.length) {
            return false;
        }

        var currentIndex = parseInt($box.data('bsnActiveIndex'), 10);
        if (!isFinite(currentIndex)) {
            currentIndex = -1;
        }

        if (isDown) {
            e.preventDefault();
            bsnSetSearchDropdownActive($box, itemSelector, currentIndex + 1);
            return true;
        }

        if (isUp) {
            e.preventDefault();
            bsnSetSearchDropdownActive($box, itemSelector, currentIndex < 0 ? $items.length - 1 : currentIndex - 1);
            return true;
        }

        if (isEnter) {
            var $active = bsnSetSearchDropdownActive($box, itemSelector, currentIndex < 0 ? 0 : currentIndex);
            if ($active.length) {
                e.preventDefault();
                $active.trigger('mousedown');
                return true;
            }
        }

        return false;
    }

    function bsnSyncSearchDropdownHover($item, itemSelector) {
        if (!$item || !$item.length) return;
        var $box = $item.parent();
        var $items = bsnGetSearchDropdownItems($box, itemSelector);
        var index = $items.index($item);
        if (index >= 0) {
            bsnSetSearchDropdownActive($box, itemSelector, index);
        }
    }

    $(document).on('input change', '#bsn-articolo-external-url', function() {
        var $form = $('#bsn-form-articolo');
        var val = ($(this).val() || '').trim();
        if (!val) {
            bsnResetCampiProdottoEsterno($form);
        }
    });

    $(document).on('change', '#bsn-form-articolo select[name="inventory_mode"]', function() {
        $(this).data('inventory-manual', 1);
    });

    $(document).on('input change', '#bsn-form-articolo input[name="qty_disponibile"]', function() {
        bsnApplyInventoryModeSuggestion(false);
    });

    $(document).on('focus', '#bsn-prodotto-pubblico-search', function() {
        bsnRenderPublicProductSearchResults($(this).val() || '');
    });

    $(document).on('input', '#bsn-prodotto-pubblico-search', function() {
        $('#bsn-form-articolo').find('input[name="prodotto_pubblico_id"]').val('');
        bsnRenderSelectedPublicProductLogistics(null);
        bsnUpdatePublicProductSuggestion();
        bsnRenderPublicProductSearchResults($(this).val() || '');
    });

    $(document).on('keydown', '#bsn-prodotto-pubblico-search', function(e) {
        if (bsnHandleSearchDropdownKey(e, {
            box: $('#bsn-prodotto-pubblico-risultati'),
            itemSelector: '.bsn-prodotto-pubblico-item'
        })) {
            return;
        }
    });

    $(document).on('mousedown', '.bsn-prodotto-pubblico-item', function(e) {
        e.preventDefault();

        var product = bsnGetPublicProductById($(this).data('id'));
        if (product) {
            bsnSetSelectedPublicProduct(product);
        }
    });

    $(document).on('mouseenter', '.bsn-prodotto-pubblico-item', function() {
        bsnSyncSearchDropdownHover($(this), '.bsn-prodotto-pubblico-item');
    });

    $(document).on('click', '.bsn-apply-public-product-suggestion', function() {
        var product = bsnGetPublicProductById($(this).data('id'));
        if (product) {
            bsnSetSelectedPublicProduct(product);
        }
    });

    $(document).on('input blur', '#bsn-form-articolo input[name="codice"], #bsn-form-articolo input[name="nome"]', function() {
        bsnUpdatePublicProductSuggestion();
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#bsn-prodotto-pubblico-search, #bsn-prodotto-pubblico-risultati').length) {
            bsnResetSearchDropdownActive($('#bsn-prodotto-pubblico-risultati'), '.bsn-prodotto-pubblico-item');
            $('#bsn-prodotto-pubblico-risultati').hide();
        }
    });

    bsnApplyArticoliUiCleanup();
    bsnUpdatePublicProductSuggestion();
    
    $('#bsn-articolo-sync').on('click', function() {
        var $form = $('#bsn-form-articolo');
        var url = ($form.find('#bsn-articolo-external-url').val() || '').trim();

        if (!url) {
            alert('Inserisci l\'URL del prodotto pubblico.');
            return;
        }

        $.ajax({
            url: BSN_API.root + 'articoli/woocommerce',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: { external_product_url: url },
            success: function(response) {
                if (!response || !response.success) {
                    alert('Impossibile recuperare i dati del prodotto.');
                    return;
                }

                $form.find('#bsn-articolo-external-url').val(response.external_product_url || url);
                $form.find('#bsn-articolo-external-slug').val(response.external_product_slug || '');
                $form.find('#bsn-articolo-external-name').val(response.external_product_name || '');
                $form.find('#bsn-articolo-external-image').val(response.external_image_url || '');
                $form.find('#bsn-articolo-external-sync').val(response.external_last_sync || '');

                bsnAggiornaAnteprimaProdotto(response);
            },
            error: function(err) {
                console.error(err);
                var msg = 'Errore nel recupero dati prodotto.';
                if (err && err.responseJSON && err.responseJSON.message) {
                    msg += '\n' + err.responseJSON.message;
                }
                alert(msg);
            }
        });
    });

    function bsnGetArticoliParams(includePage) {
        var params = {
            limit: bsnListState.articoli.limit
        };
        if (includePage) {
            params.page = bsnListState.articoli.page || 1;
        }
        if (bsnListState.articoli.search) {
            params.search = bsnListState.articoli.search;
        }
        return params;
    }

    function bsnRenderArticoliPagination(response) {
        var total = parseInt(response.total, 10) || 0;
        var page = parseInt(response.page, 10) || 1;
        var perPage = parseInt(response.per_page, 10) || bsnListState.articoli.limit || 12;
        var totalPages = parseInt(response.total_pages, 10) || 1;
        var from = parseInt(response.from, 10) || 0;
        var to = parseInt(response.to, 10) || 0;

        bsnListState.articoli.page = page;
        bsnListState.articoli.total = total;
        bsnListState.articoli.totalPages = totalPages;

        var disabledPrev = page <= 1 ? ' disabled' : '';
        var disabledNext = page >= totalPages ? ' disabled' : '';
        var label = total > 0
            ? 'Risultati ' + from + '-' + to + ' di ' + total
            : 'Nessun risultato';

        var html = '' +
            '<div class="bsn-table-pagination" id="bsn-articoli-pagination">' +
                '<div class="bsn-table-pagination-summary">' + label + '</div>' +
                '<div class="bsn-table-pagination-controls">' +
                    '<button type="button" class="btn btn-secondary bsn-articoli-page-prev"' + disabledPrev + '>« Precedente</button>' +
                    '<span>Pagina ' + page + ' di ' + totalPages + '</span>' +
                    '<button type="button" class="btn btn-secondary bsn-articoli-page-next"' + disabledNext + '>Successiva »</button>' +
                    '<label class="bsn-table-goto-label">Vai a pagina <input type="number" id="bsn-articoli-page-goto" min="1" max="' + totalPages + '" value="' + page + '"></label>' +
                '</div>' +
            '</div>';

        $('#bsn-articoli-pagination').remove();
        $('#bsn-lista-articoli').after(html);
    }

    function bsnApriHtmlStampabile(html) {
        var win = window.open('', '_blank');
        if (!win) {
            alert('Popup bloccato dal browser. Consenti i popup per aprire la versione stampabile.');
            return;
        }
        win.document.open();
        win.document.write(html);
        win.document.close();
        win.focus();
    }

    function bsnCaricaArticoli() {
    var params = bsnGetArticoliParams(true);

    $.ajax({
        url: BSN_API.root + 'articoli',
        method: 'GET',
        data: params,
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        success: function(response) {
            response = response || {};
            var articoli = Array.isArray(response) ? response : (response.articoli || []);
            BSN_ARTICOLI_CACHE = articoli;

            var wrapper = $('#bsn-lista-articoli');
            wrapper.empty();

            if (!articoli.length) {
                wrapper.html('<p>Nessun articolo salvato.</p>');
                bsnRenderArticoliPagination($.extend({}, response, {
                    total: response.total || 0,
                    page: response.page || 1,
                    per_page: response.per_page || bsnListState.articoli.limit,
                    total_pages: response.total_pages || 1,
                    from: 0,
                    to: 0
                }));
                return;
            }
            
            var html = '' +
                    '<table class="bsn-tabella" id="bsn-articoli-table">' +
                        '<thead><tr>' +
                            '<th>ID</th><th>Nome</th><th>Codice</th><th>Prodotto pubblico</th><th>Prezzo<br>/giorno</th><th>Valore bene</th><th>Q.tà</th><th>Ubicazione</th><th>Correlati</th><th>Azioni</th>' +
                        '</tr></thead><tbody>';

            articoli.forEach(function(a) {
                html += '' +
                    '<tr class="bsn-articolo-row" data-id="' + a.id + '" data-codice="' + (a.codice || '') + '">' +
                        '<td>' + a.id + '</td><td>' + (a.nome || '') + '</td><td>' + (a.codice || '') + '</td>' +
                        '<td>' + (a.prodotto_pubblico_titolo || '-') + '</td>' +
                        '<td style="text-align:right;">' + (a.prezzo_giorno || '0') + '</td>' +
                        '<td style="text-align:right;">' + (a.valore_bene || '0') + '</td>' +
                        '<td style="text-align:center;">' + (a.qty_disponibile || '0') + '</td>' +
                        '<td>' + (a.ubicazione || '') + '</td>' +
                        '<td>' + (a.correlati ? bsnFormattaCorrelati(a.correlati) : '-') + '</td>' +
                        '<td><div class="bsn-azioni-grid">' +
                            '<button type="button" class="btn bsn-articolo-details">🔍Dettagli</button>' +
                            '<button type="button" class="btn bsn-articolo-qr" data-codice="' + (a.codice || '') + '">📱 QR</button>' +
                            '<button type="button" class="btn bsn-articolo-edit">✏️ Modifica</button>' +
                            '<button type="button" class="btn bsn-articolo-duplica">📑 Duplica</button>' +
                            '<button type="button" class="btn bsn-articolo-clona">🔁 Clona</button>' +
                            '<button type="button" class="btn bsn-articolo-delete" style="color:#b00;">🗑️ Elimina</button>' +
                        '</div></td>' +
                    '</tr>' +
                    '<tr class="bsn-articolo-row-details" data-id="' + a.id + '" style="display:none;">' +
                         '<td colspan="10"><div class="bsn-articolo-details-container"></div></td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            wrapper.html(html);
            bsnRenderArticoliPagination(response);

            bsnAggiornaKitSelectOptions();
            bsnAggiornaKitMap();
            bsnRenderKitComponenti();
        },
        error: function(err) {
            console.error(err);
            $('#bsn-lista-articoli').html('<p>Errore nel caricare gli articoli.</p>');
        }
    });
}

    $(document).on('click', '.bsn-articoli-page-prev', function() {
        if (bsnListState.articoli.page > 1) {
            bsnListState.articoli.page--;
            bsnCaricaArticoli();
        }
    });

    $(document).on('click', '.bsn-articoli-page-next', function() {
        if (bsnListState.articoli.page < bsnListState.articoli.totalPages) {
            bsnListState.articoli.page++;
            bsnCaricaArticoli();
        }
    });

    $(document).on('change', '#bsn-articoli-page-goto', function() {
        var totalPages = bsnListState.articoli.totalPages || 1;
        var page = parseInt($(this).val(), 10) || 1;
        if (page < 1) page = 1;
        if (page > totalPages) page = totalPages;
        if (page !== bsnListState.articoli.page) {
            bsnListState.articoli.page = page;
            bsnCaricaArticoli();
        } else {
            $(this).val(page);
        }
    });

    $(document).on('click', '#bsn-articoli-magazzino-export', function() {
        var params = bsnGetArticoliParams(false);
        var $btn = $(this);
        $btn.prop('disabled', true).text('Esporto...');

        $.ajax({
            url: BSN_API.root + 'articoli/magazzino-export',
            method: 'GET',
            data: params,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                if (response && response.csv_base64) {
                    bsnScaricaBase64Csv(
                        response.csv_base64,
                        response.filename || 'bsn-magazzino-articoli.csv',
                        response.mime || 'text/csv;charset=utf-8'
                    );
                } else {
                    alert('Export completato ma file non disponibile.');
                }
            },
            error: function(err) {
                console.error(err);
                alert('Errore durante export CSV magazzino.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Esporta CSV magazzino');
            }
        });
    });

    $(document).on('click', '#bsn-articoli-magazzino-print', function() {
        var params = bsnGetArticoliParams(false);
        var $btn = $(this);
        $btn.prop('disabled', true).text('Preparo stampa...');

        $.ajax({
            url: BSN_API.root + 'articoli/magazzino-print',
            method: 'GET',
            data: params,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                if (response && response.html) {
                    bsnApriHtmlStampabile(response.html);
                } else {
                    alert('Vista stampabile non disponibile.');
                }
            },
            error: function(err) {
                console.error(err);
                alert('Errore durante preparazione stampa magazzino.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Stampa elenco magazzino');
            }
        });
    });

/* ==== KIT ==== */

function bsnAggiornaKitMap() {
    BSN_KIT_MAP_BY_ARTICOLO = {};
    (BSN_KITS_CACHE || []).forEach(function(kit) {
        if (kit && kit.articolo_kit_id) {
            BSN_KIT_MAP_BY_ARTICOLO[parseInt(kit.articolo_kit_id, 10)] = kit;
        }
    });
}

function bsnAggiornaKitSelectOptions() {
    var $selectKitArticolo = $('#bsn-kit-articolo-id');
    var opzioni = '<option value="">-- Seleziona articolo --</option>';
    (BSN_ARTICOLI_CACHE || []).forEach(function(a) {
        var label = '';
        if (a.codice) {
            label += '[' + a.codice + '] ';
        }
        label += (a.nome || ('Articolo #' + a.id));
        opzioni += '<option value="' + a.id + '">' + label + '</option>';
    });
    $selectKitArticolo.html(opzioni);

    $('#bsn-kit-componenti-wrapper .bsn-kit-componente-articolo').each(function() {
        var current = $(this).val();
        $(this).html(opzioni).val(current || '');
    });
}

function bsnCaricaKits() {
    $.ajax({
        url: BSN_API.root + 'kits',
        method: 'GET',
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        success: function(response) {
            BSN_KITS_CACHE = response.kits || [];
            bsnAggiornaKitMap();
            bsnRenderListaKits();
            bsnRenderKitComponenti();
        },
        error: function(err) {
            console.error(err);
            $('#bsn-lista-kits').html('<p>Errore nel caricare i kit.</p>');
        }
    });
}

function bsnRenderListaKits() {
    var $wrapper = $('#bsn-lista-kits');
    $wrapper.empty();

    if (!BSN_KITS_CACHE || !BSN_KITS_CACHE.length) {
        $wrapper.html('<p>Nessun kit salvato.</p>');
        return;
    }

    var html = '<table class="bsn-tabella" id="bsn-kits-table"><thead><tr>' +
         '<th>ID</th><th>Nome kit</th><th>Articolo kit</th><th>Componenti</th><th>Azioni</th></tr></thead><tbody>';

    BSN_KITS_CACHE.forEach(function(kit) {
        var articoloLabel = '';
        if (kit.articolo_kit_codice) {
            articoloLabel += '[' + kit.articolo_kit_codice + '] ';
        }
        articoloLabel += (kit.articolo_kit_nome || ('Articolo #' + kit.articolo_kit_id));

        var componentiLabel = (kit.components || []).map(function(c) {
            var label = c.ruolo + ' (' + c.qty + 'x) ';
            if (c.articolo_codice) {
                label += '[' + c.articolo_codice + '] ';
            }
            label += (c.articolo_nome || ('Articolo #' + c.articolo_id));
            if (c.gruppo_equivalenza) {
                label += ' — ' + c.gruppo_equivalenza;
            }
            return label;
        }).join(', ');

        html += '<tr data-kit-id="' + kit.id + '">' +
            '<td>' + kit.id + '</td>' +
            '<td>' + (kit.nome_kit || '') + '</td>' +
            '<td>' + articoloLabel + '</td>' +
            '<td>' + (componentiLabel || '-') + '</td>' +
            '<td>' +
                '<div class="bsn-azioni-grid">' +
                    '<button type="button" class="btn bsn-kit-details">🔍 Dettagli</button>' +
                    '<button type="button" class="btn bsn-kit-edit">✏️ Modifica</button>' +
                    '<button type="button" class="btn bsn-kit-delete" style="color:#b00;">🗑️ Elimina</button>' +
                '</div>' +
            '</td>' +
            '</tr>' +
        '<tr class="bsn-kit-row-details" data-id="' + kit.id + '" style="display:none;">' +
            '<td colspan="5">' +
                '<div class="bsn-kit-details-container"><em>Caricamento dettagli...</em></div>' +
            '</td>' +
        '</tr>';
    });

    html += '</tbody></table>';
    $wrapper.html(html);
}

function bsnAggiungiKitComponenteRow() {
    var opzioni = '<option value="">-- Seleziona articolo --</option>';
    (BSN_ARTICOLI_CACHE || []).forEach(function(a) {
        var label = '';
        if (a.codice) {
            label += '[' + a.codice + '] ';
        }
        label += (a.nome || ('Articolo #' + a.id));
        opzioni += '<option value="' + a.id + '">' + label + '</option>';
    });

    var $row = $(
        '<div class="bsn-kit-componente-row" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:6px; align-items:center;">' +
            '<select class="bsn-kit-componente-articolo" style="flex:2 1 220px;">' + opzioni + '</select>' +
            '<input type="text" class="bsn-kit-componente-ruolo" placeholder="Ruolo" style="flex:1 1 120px;">' +
            '<input type="number" class="bsn-kit-componente-qty" min="1" value="1" style="width:70px;">' +
            '<label style="display:flex; align-items:center; gap:4px; font-size:12px;">' +
                '<input type="checkbox" class="bsn-kit-componente-default" checked> Default' +
            '</label>' +
            '<label style="display:flex; align-items:center; gap:4px; font-size:12px;">' +
                '<input type="checkbox" class="bsn-kit-componente-selectable" checked> Selezionabile' +
            '</label>' +
            '<input type="text" class="bsn-kit-componente-gruppo" placeholder="Gruppo equivalenza" style="flex:1 1 160px;">' +
            '<button type="button" class="btn bsn-kit-componente-remove">X</button>' +
        '</div>'
    );

    $('#bsn-kit-componenti-wrapper').append($row);
}

$(document).on('click', '#bsn-kit-componente-add', function() {
    bsnAggiungiKitComponenteRow();
});

$(document).on('click', '.bsn-kit-componente-remove', function() {
    $(this).closest('.bsn-kit-componente-row').remove();
});

$('#bsn-form-kit').on('submit', function(e) {
    e.preventDefault();

    var $form = $(this);
    var kitId = parseInt($form.find('#bsn-kit-id').val(), 10);
    var nomeKit = ($form.find('input[name="nome_kit"]').val() || '').trim();
    var articoloKitId = parseInt($form.find('select[name="articolo_kit_id"]').val(), 10);
    var note = $form.find('textarea[name="note"]').val() || '';

    if (!nomeKit || !articoloKitId) {
        alert('Nome kit e articolo kit sono obbligatori.');
        return;
    }

    var componenti = [];
    $('#bsn-kit-componenti-wrapper .bsn-kit-componente-row').each(function() {
        var articoloId = parseInt($(this).find('.bsn-kit-componente-articolo').val(), 10);
        var ruolo = ($(this).find('.bsn-kit-componente-ruolo').val() || '').trim();
        var qty = parseInt($(this).find('.bsn-kit-componente-qty').val(), 10) || 0;
        var gruppo = ($(this).find('.bsn-kit-componente-gruppo').val() || '').trim();
        var isDefault = $(this).find('.bsn-kit-componente-default').is(':checked') ? 1 : 0;
        var isSelectable = $(this).find('.bsn-kit-componente-selectable').is(':checked') ? 1 : 0;

        if (articoloId && ruolo && qty > 0 && gruppo) {
            componenti.push({
                articolo_id: articoloId,
                ruolo: ruolo,
                qty: qty,
                gruppo_equivalenza: gruppo,
                is_default: isDefault,
                is_selectable: isSelectable
            });
        }
    });

    if (!componenti.length) {
        alert('Inserisci almeno un componente valido.');
        return;
    }
    
    var endpoint = kitId ? 'kits/update' : 'kits';

    $.ajax({
        url: BSN_API.root + endpoint,
        method: 'POST',
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        data: {
            id: kitId || undefined,
            nome_kit: nomeKit,
            articolo_kit_id: articoloKitId,
            note: note,
            componenti: JSON.stringify(componenti)
        },
        success: function() {
            alert(kitId ? 'Kit aggiornato correttamente.' : 'Kit salvato correttamente.');
            $form[0].reset();
            $('#bsn-kit-id').val('');
            $('#bsn-kit-componenti-wrapper').empty();
            bsnAggiungiKitComponenteRow();
            bsnCaricaKits();
        },
        error: function(err) {
            console.error(err);
            alert('Errore nel salvataggio del kit.');
        }
    });
});

$(document).on('click', '.bsn-kit-edit', function() {
    var $row = $(this).closest('tr');
    var kitId = parseInt($row.data('kit-id'), 10);
    if (!kitId) {
        return;
    }

    var kit = (BSN_KITS_CACHE || []).find(function(k) {
        return parseInt(k.id, 10) === kitId;
    });

    if (!kit) {
        alert('Kit non trovato.');
        return;
    }

    $('#bsn-kit-id').val(kit.id);
    $('#bsn-form-kit input[name="nome_kit"]').val(kit.nome_kit || '');
    $('#bsn-form-kit select[name="articolo_kit_id"]').val(kit.articolo_kit_id || '');
    $('#bsn-form-kit textarea[name="note"]').val(kit.note || '');

    $('#bsn-kit-componenti-wrapper').empty();
    (kit.components || []).forEach(function(comp) {
        bsnAggiungiKitComponenteRow();
        var $rowComp = $('#bsn-kit-componenti-wrapper .bsn-kit-componente-row').last();
        $rowComp.find('.bsn-kit-componente-articolo').val(comp.articolo_id || '');
        $rowComp.find('.bsn-kit-componente-ruolo').val(comp.ruolo || '');
        $rowComp.find('.bsn-kit-componente-qty').val(comp.qty || 1);
        $rowComp.find('.bsn-kit-componente-default').prop('checked', parseInt(comp.is_default, 10) === 1);
        $rowComp.find('.bsn-kit-componente-selectable').prop('checked', parseInt(comp.is_selectable, 10) === 1);
        $rowComp.find('.bsn-kit-componente-gruppo').val(comp.gruppo_equivalenza || '');
    });

    $('html, body').animate({
        scrollTop: $('#bsn-kit-section').offset().top - 20
    }, 300);
});

$(document).on('click', '.bsn-kit-details', function() {
    var $row = $(this).closest('tr');
    var kitId = parseInt($row.data('kit-id'), 10);
    if (!kitId) {
        return;
    }

    var $detailsRow = $('.bsn-kit-row-details[data-id="' + kitId + '"]');
    var $container = $detailsRow.find('.bsn-kit-details-container');

    if ($detailsRow.is(':visible')) {
        $detailsRow.hide();
        return;
    }

    $detailsRow.show();
    $container.html('<em>Caricamento dettagli...</em>');

    var kit = (BSN_KITS_CACHE || []).find(function(k) {
        return parseInt(k.id, 10) === kitId;
    });

    if (!kit) {
        $container.html('<em>Kit non trovato.</em>');
        return;
    }

    var articoloKitLabel = '';
    if (kit.articolo_kit_codice) {
        articoloKitLabel += '[' + kit.articolo_kit_codice + '] ';
    }
    articoloKitLabel += (kit.articolo_kit_nome || ('Articolo #' + kit.articolo_kit_id));

    var html = '';
    html += '<div style="margin-bottom:8px;"><strong>Nome kit:</strong> ' + (kit.nome_kit || '-') + '</div>';
    html += '<div style="margin-bottom:8px;"><strong>Articolo kit:</strong> ' + articoloKitLabel + '</div>';
    html += '<div style="margin-bottom:8px;"><strong>Note kit:</strong> ' + (kit.note || '-') + '</div>';

    if (kit.components && kit.components.length) {
        html += '<div style="margin-top:8px;"><strong>Componenti:</strong></div>';
        html += '<table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:6px;">' +
            '<thead><tr style="background:#f3f3f3;">' +
                '<th style="padding:4px; border:1px solid #ddd; text-align:left;">Ruolo</th>' +
                '<th style="padding:4px; border:1px solid #ddd; text-align:center;">Qty</th>' +
                '<th style="padding:4px; border:1px solid #ddd; text-align:left;">Articolo</th>' +
                '<th style="padding:4px; border:1px solid #ddd; text-align:center;">Default</th>' +
                '<th style="padding:4px; border:1px solid #ddd; text-align:center;">Selezionabile</th>' +
                '<th style="padding:4px; border:1px solid #ddd; text-align:left;">Gruppo equiv.</th>' +
            '</tr></thead><tbody>';

        kit.components.forEach(function(comp) {
            var articoloLabel = '';
            if (comp.articolo_codice) {
                articoloLabel += '[' + comp.articolo_codice + '] ';
            }
            articoloLabel += (comp.articolo_nome || ('Articolo #' + comp.articolo_id));

            html += '<tr>' +
                '<td style="padding:4px; border:1px solid #ddd;">' + (comp.ruolo || '-') + '</td>' +
                '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + (comp.qty || 1) + '</td>' +
                '<td style="padding:4px; border:1px solid #ddd;">' + articoloLabel + '</td>' +
                '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + (parseInt(comp.is_default, 10) === 1 ? 'Sì' : 'No') + '</td>' +
                '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + (parseInt(comp.is_selectable, 10) === 1 ? 'Sì' : 'No') + '</td>' +
                '<td style="padding:4px; border:1px solid #ddd;">' + (comp.gruppo_equivalenza || '-') + '</td>' +
            '</tr>';
        });

        html += '</tbody></table>';
    } else {
        html += '<div><em>Nessun componente associato.</em></div>';
    }

    $container.html(html);
});

$(document).on('click', '.bsn-kit-delete', function() {
    var $row = $(this).closest('tr');
    var kitId = parseInt($row.data('kit-id'), 10);
    if (!kitId) {
        return;
    }

    if (!confirm('Vuoi eliminare definitivamente questo kit?')) {
        return;
    }

    $.ajax({
        url: BSN_API.root + 'kits/delete',
        method: 'POST',
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        data: { id: kitId },
        success: function() {
            alert('Kit eliminato.');
            if (parseInt($('#bsn-kit-id').val(), 10) === kitId) {
                $('#bsn-form-kit')[0].reset();
                $('#bsn-kit-id').val('');
                $('#bsn-kit-componenti-wrapper').empty();
                bsnAggiungiKitComponenteRow();
            }
            bsnCaricaKits();
        },
        error: function(err) {
            console.error(err);
            alert('Errore nell\'eliminazione del kit.');
        }
    });
});

/* ==== PROFILI SCONTI (precompilazione articoli) ==== */

// Cache profili sconti
var BSN_PROFILI_SCONTI_CACHE = [];

// Carica profili dal DB
function bsnCaricaProfiliSconti() {
    $.ajax({
        url: BSN_API.root + 'profili-sconti',
        method: 'GET',
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        success: function(response) {
            BSN_PROFILI_SCONTI_CACHE = response.profili || [];
            bsnAggiornaProfiliDropdown();
        },
        error: function(err) {
            console.error('Errore caricamento profili sconti:', err);
        }
    });

// Aggiorna il dropdown profili
function bsnAggiornaProfiliDropdown() {
    var $dropdown = $('#bsn-profili-sconti-dropdown');
    $dropdown.empty();
    $dropdown.append('<option value="">-- Seleziona profilo --</option>');
    
    BSN_PROFILI_SCONTI_CACHE.forEach(function(p) {
        $dropdown.append('<option value="' + p.id + '">' + p.nome + '</option>');
    });
}

// Applica profilo ai campi sconto
function bsnApplicaProfilo(profiloId) {
    var profilo = BSN_PROFILI_SCONTI_CACHE.find(function(p) {
        return parseInt(p.id, 10) === parseInt(profiloId, 10);
    });
    
    if (!profilo) {
        alert('Profilo non trovato.');
        return;
    }
    
    $('#bsn-form-articolo input[name="sconto_standard"]').val(profilo.sconto_standard);
    $('#bsn-form-articolo input[name="sconto_fidato"]').val(profilo.sconto_fidato);
    $('#bsn-form-articolo input[name="sconto_premium"]').val(profilo.sconto_premium);
    $('#bsn-form-articolo input[name="sconto_service"]').val(profilo.sconto_service);
    $('#bsn-form-articolo input[name="sconto_collaboratori"]').val(profilo.sconto_collaboratori);
    
    $('#bsn-profili-sconti-messaggio')
        .text('✓ Applicato profilo: ' + profilo.nome + ' (puoi modificare i campi prima di salvare)')
        .show()
        .delay(4000)
        .fadeOut();
}

// Eventi UI profili sconti
$(document).on('change', '#bsn-profili-sconti-dropdown', function() {
    var profiloId = $(this).val();
    if (profiloId) {
        bsnApplicaProfilo(profiloId);
    }
});

$(document).on('click', '#bsn-salva-profilo-btn', function() {
    var nome = prompt('Nome del profilo sconti:');
    if (!nome || !nome.trim()) {
        return;
    }
    nome = nome.trim();
    
    var dati = {
        nome: nome,
        sconto_standard: $('#bsn-form-articolo input[name="sconto_standard"]').val(),
        sconto_fidato: $('#bsn-form-articolo input[name="sconto_fidato"]').val(),
        sconto_premium: $('#bsn-form-articolo input[name="sconto_premium"]').val(),
        sconto_service: $('#bsn-form-articolo input[name="sconto_service"]').val(),
        sconto_collaboratori: $('#bsn-form-articolo input[name="sconto_collaboratori"]').val()
    };
    
    $.ajax({
        url: BSN_API.root + 'profili-sconti',
        method: 'POST',
        data: dati,
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        success: function(response) {
            alert('✓ Profilo salvato: ' + response.nome);
            bsnCaricaProfiliSconti();
        },
        error: function(err) {
            var msg = 'Errore nel salvataggio profilo.';
            if (err.responseJSON && err.responseJSON.message) {
                msg = err.responseJSON.message;
            }
            alert('✗ ' + msg);
        }
    });
});

$(document).on('click', '#bsn-elimina-profilo-btn', function() {
    var profiloId = $('#bsn-profili-sconti-dropdown').val();
    if (!profiloId) {
        alert('Seleziona prima un profilo da eliminare.');
        return;
    }
    
    var profilo = BSN_PROFILI_SCONTI_CACHE.find(function(p) {
        return parseInt(p.id, 10) === parseInt(profiloId, 10);
    });
    
    if (!profilo) {
        alert('Profilo non trovato.');
        return;
    }
    
    if (!confirm('Sei sicuro di voler eliminare il profilo "' + profilo.nome + '"?\n\nGli articoli già creati NON saranno modificati.')) {
        return;
    }
    
    $.ajax({
        url: BSN_API.root + 'profili-sconti/delete',
        method: 'POST',
        data: { id: profiloId },
        beforeSend: function(xhr) {
            xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
        },
        success: function(response) {
            alert('✓ Profilo eliminato: ' + response.nome);
            $('#bsn-profili-sconti-dropdown').val('');
            bsnCaricaProfiliSconti();
        },
        error: function(err) {
            console.error(err);
            alert('Errore nell\'eliminazione profilo.');
        }
    });
});

    }

    /// Click pulsante "Genera QR"
    $(document).on('click', '.bsn-articolo-qr', function() {
        var codice = $(this).data('codice');

        if (!codice) {
            alert('Codice articolo non trovato.');
            return;
        }

        // Pulisci il container precedente
        $('#bsn-qr-container').empty();
        $('#bsn-qr-code-text').text(codice);

        // Genera QR code dentro il box 180x180 (dimensione normale)
        new QRCode(document.getElementById('bsn-qr-container'), {
            text: codice,
            width: 150,
            height: 150,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });

        // Mostra il modal centrato (flex)
        $('#bsn-modal-qr')
            .css('display', 'flex')
            .hide()
            .fadeIn();
    });

    // Click pulsante "Chiudi" nel modal
    $(document).on('click', '#bsn-qr-close', function() {
        $('#bsn-modal-qr').fadeOut();
    });

    // Click pulsante "Stampa QR" (versione semplice)
    $(document).on('click', '#bsn-qr-print', function() {
        var qrHtml  = $('#bsn-qr-container').html();
        var qrLabel = $('#bsn-qr-code-text').text() || '';

        var printWindow = window.open('', '', 'width=400,height=400');

        printWindow.document.write('<html><head><title>Stampa QR</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('body { margin: 20px; padding: 0; text-align:center; font-family: Arial, sans-serif; }');
        printWindow.document.write('.qr-img { margin-bottom: 10px; }');
        printWindow.document.write('.qr-label { font-size: 12px; font-weight: bold; }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');

        printWindow.document.write('<div class="qr-img">' + qrHtml + '</div>');
        if (qrLabel) {
            printWindow.document.write('<div class="qr-label">' + qrLabel + '</div>');
        }

        printWindow.document.write('</body></html>');
        printWindow.document.close();

        setTimeout(function() {
            printWindow.focus();
            printWindow.print();
        }, 250);
    });

    // ===== HANDLER AZIONI ARTICOLI =====

    // Modifica: carica i dati nel form articoli
    $(document).on('click', '.bsn-articolo-edit', function() {
        var $row = $(this).closest('tr');
        var id   = $row.data('id');

        if (!id) return;

        var art = BSN_ARTICOLI_CACHE.find(function(a){
            return parseInt(a.id,10) === parseInt(id,10);
        });
        if (!art) {
            alert('Articolo non trovato in cache.');
            return;
        }

        var $form = $('#bsn-form-articolo');

        $form.find('input[name="nome"]').val(art.nome || '');
        $form.find('input[name="codice"]').val(art.codice || '');
        $form.find('input[name="prezzo_giorno"]').val(art.prezzo_giorno || '');
        $form.find('input[name="valore_bene"]').val(art.valore_bene || '');
        $form.find('input[name="sconto_standard"]').val(art.sconto_standard || 0);
        $form.find('input[name="sconto_fidato"]').val(art.sconto_fidato || 0);
        $form.find('input[name="sconto_premium"]').val(art.sconto_premium || 0);
        $form.find('input[name="sconto_service"]').val(art.sconto_service || 0);
        $form.find('input[name="sconto_collaboratori"]').val(art.sconto_collaboratori || 0);
        $form.find('input[name="qty_discount_enabled"]').prop('checked', parseInt(art.qty_discount_enabled, 10) === 1);
        $form.find('input[name="qty_discount_start_qty"]').val(art.qty_discount_start_qty || '');
        $form.find('input[name="qty_discount_max_qty"]').val(art.qty_discount_max_qty || '');
        $form.find('input[name="qty_discount_max_pct"]').val(art.qty_discount_max_pct || 0);

        $form.find('input[name="qty_disponibile"]').val(art.qty_disponibile || 1);
        $form.find('select[name="inventory_mode"]').val(art.inventory_mode || 'seriale').data('inventory-manual', 1);
        bsnSetSelectedPublicProduct(art.prodotto_pubblico_id ? {
            id: art.prodotto_pubblico_id,
            title: art.prodotto_pubblico_titolo || '',
            sede_operativa: art.prodotto_pubblico_sede_operativa || '',
            veicolo_minimo: art.prodotto_pubblico_veicolo_minimo || '',
            larghezza_cm: art.prodotto_pubblico_larghezza_cm || '',
            altezza_cm: art.prodotto_pubblico_altezza_cm || '',
            profondita_cm: art.prodotto_pubblico_profondita_cm || '',
            peso_kg: art.prodotto_pubblico_peso_kg || ''
        } : null);
        bsnUpdatePublicProductSuggestion();
        $form.find('input[name="min_qty"]').val(art.min_qty || 1);
        $form.find('select[name="stato_utilizzabilita"]').val(art.stato_utilizzabilita || 'disponibile');
        $form.find('input[name="ubicazione"]').val(art.ubicazione || '');
        $form.find('textarea[name="note"]').val(art.note || '');
        $form.find('textarea[name="note_logistiche"]').val(art.note_logistiche || '');
        $form.find('#bsn-articolo-external-url').val(art.external_product_url || '');
        $form.find('#bsn-articolo-external-slug').val(art.external_product_slug || '');
        $form.find('#bsn-articolo-external-name').val(art.external_product_name || '');
        $form.find('#bsn-articolo-external-image').val(art.external_image_url || '');
        $form.find('#bsn-articolo-external-sync').val(art.external_last_sync || '');
        $form.data('articolo-id', id);
        bsnAggiornaAnteprimaProdotto({
            external_product_name: art.external_product_name || '',
            external_product_url: art.external_product_url || '',
            external_image_url: art.external_image_url || ''
        });

        // Ricostruisci correlati nel form
        $('#bsn-correlati-wrapper').empty();
        if (art.correlati) {
            try {
                var corr = JSON.parse(art.correlati);
                if (Array.isArray(corr) && corr.length) {
                    corr.forEach(function(c) {
                        var rowHtml =
                            '<div class="bsn-correlato-row">' +
                                '<input type="text" name="correlato_nome[]" value="' + (c.nome || '') + '" placeholder="Nome correlato">' +
                                '<input type="number" name="correlato_qty[]" value="' + (c.qty || 1) + '" placeholder="Q.tà" min="1" style="max-width:80px;">' +
                                '<button type="button" class="btn bsn-correlato-remove">X</button>' +
                            '</div>';
                        $('#bsn-correlati-wrapper').append(rowHtml);
                    });
                } else {
                    $('#bsn-correlati-wrapper').html(
                        '<div class="bsn-correlato-row">' +
                            '<input type="text" name="correlato_nome[]" placeholder="Nome correlato">' +
                            '<input type="number" name="correlato_qty[]" placeholder="Q.tà" min="1" value="1" style="max-width:80px;">' +
                            '<button type="button" class="btn bsn-correlato-remove">X</button>' +
                        '</div>'
                    );
                }
            } catch(e) {
                $('#bsn-correlati-wrapper').html(
                    '<div class="bsn-correlato-row">' +
                        '<input type="text" name="correlato_nome[]" placeholder="Nome correlato">' +
                        '<input type="number" name="correlato_qty[]" placeholder="Q.tà" min="1" value="1" style="max-width:80px;">' +
                        '<button type="button" class="btn bsn-correlato-remove">X</button>' +
                    '</div>'
                );
            }
        } else {
            $('#bsn-correlati-wrapper').html(
                '<div class="bsn-correlato-row">' +
                    '<input type="text" name="correlato_nome[]" placeholder="Nome correlato">' +
                    '<input type="number" name="correlato_qty[]" placeholder="Q.tà" min="1" value="1" style="max-width:80px;">' +
                    '<button type="button" class="btn bsn-correlato-remove">X</button>' +
                '</div>'
            );
        }

        // Flag scalare
        $form.find('input[name="noleggio_scalare"]').prop('checked', parseInt(art.noleggio_scalare,10) === 1);

        $('html, body').animate({
            scrollTop: $('#articoli').offset().top - 20
        }, 300);
    });

    // Duplica: copia dati nel form ma SENZA id (nuovo articolo)
    $(document).on('click', '.bsn-articolo-duplica', function() {
        var $row = $(this).closest('tr');
        var id   = $row.data('id');

        if (!id) return;

        var art = BSN_ARTICOLI_CACHE.find(function(a){
            return parseInt(a.id,10) === parseInt(id,10);
        });
        if (!art) {
            alert('Articolo non trovato in cache.');
            return;
        }

        var $form = $('#bsn-form-articolo');

        $form.find('input[name="nome"]').val(art.nome || '');
        $form.find('input[name="codice"]').val(art.codice || '');
        $form.find('input[name="prezzo_giorno"]').val(art.prezzo_giorno || '');
        $form.find('input[name="valore_bene"]').val(art.valore_bene || '');
        $form.find('input[name="sconto_standard"]').val(art.sconto_standard || 0);
        $form.find('input[name="sconto_fidato"]').val(art.sconto_fidato || 0);
        $form.find('input[name="sconto_premium"]').val(art.sconto_premium || 0);
        $form.find('input[name="sconto_service"]').val(art.sconto_service || 0);
        $form.find('input[name="sconto_collaboratori"]').val(art.sconto_collaboratori || 0);
        $form.find('input[name="qty_discount_enabled"]').prop('checked', parseInt(art.qty_discount_enabled, 10) === 1);
        $form.find('input[name="qty_discount_start_qty"]').val(art.qty_discount_start_qty || '');
        $form.find('input[name="qty_discount_max_qty"]').val(art.qty_discount_max_qty || '');
        $form.find('input[name="qty_discount_max_pct"]').val(art.qty_discount_max_pct || 0);

        $form.find('input[name="qty_disponibile"]').val(art.qty_disponibile || 1);
        $form.find('select[name="inventory_mode"]').val(art.inventory_mode || 'seriale').data('inventory-manual', 1);
        bsnSetSelectedPublicProduct(art.prodotto_pubblico_id ? {
            id: art.prodotto_pubblico_id,
            title: art.prodotto_pubblico_titolo || '',
            sede_operativa: art.prodotto_pubblico_sede_operativa || '',
            veicolo_minimo: art.prodotto_pubblico_veicolo_minimo || '',
            larghezza_cm: art.prodotto_pubblico_larghezza_cm || '',
            altezza_cm: art.prodotto_pubblico_altezza_cm || '',
            profondita_cm: art.prodotto_pubblico_profondita_cm || '',
            peso_kg: art.prodotto_pubblico_peso_kg || ''
        } : null);
        bsnUpdatePublicProductSuggestion();
        $form.find('input[name="min_qty"]').val(art.min_qty || 1);
        $form.find('select[name="stato_utilizzabilita"]').val(art.stato_utilizzabilita || 'disponibile');
        $form.find('input[name="ubicazione"]').val(art.ubicazione || '');
        $form.find('textarea[name="note"]').val(art.note || '');
        $form.find('textarea[name="note_logistiche"]').val(art.note_logistiche || '');
        $form.find('#bsn-articolo-external-url').val(art.external_product_url || '');
        $form.find('#bsn-articolo-external-slug').val(art.external_product_slug || '');
        $form.find('#bsn-articolo-external-name').val(art.external_product_name || '');
        $form.find('#bsn-articolo-external-image').val(art.external_image_url || '');
        $form.find('#bsn-articolo-external-sync').val(art.external_last_sync || '');
        $form.removeData('articolo-id'); // nuovo record
        bsnAggiornaAnteprimaProdotto({
            external_product_name: art.external_product_name || '',
            external_product_url: art.external_product_url || '',
            external_image_url: art.external_image_url || ''
        });

        // Correlati come sopra (riuso la logica, semplifico)
        $('#bsn-correlati-wrapper').empty();
        if (art.correlati) {
            try {
                var corr = JSON.parse(art.correlati);
                if (Array.isArray(corr) && corr.length) {
                    corr.forEach(function(c) {
                        var rowHtml =
                            '<div class="bsn-correlato-row">' +
                                '<input type="text" name="correlato_nome[]" value="' + (c.nome || '') + '" placeholder="Nome correlato">' +
                                '<input type="number" name="correlato_qty[]" value="' + (c.qty || 1) + '" placeholder="Q.tà" min="1" style="max-width:80px;">' +
                                '<button type="button" class="btn bsn-correlato-remove">X</button>' +
                            '</div>';
                        $('#bsn-correlati-wrapper').append(rowHtml);
                    });
                }
            } catch(e) {}
        }

        $form.find('input[name="noleggio_scalare"]').prop('checked', parseInt(art.noleggio_scalare,10) === 1);

        $('html, body').animate({
            scrollTop: $('#articoli').offset().top - 20
        }, 300);
    });

    // Clona lato server (incrementa codice: CDJ2000#1 -> CDJ2000#2)
    $(document).on('click', '.bsn-articolo-clona', function() {
        var $row = $(this).closest('tr');
        var id   = $row.data('id');

        if (!id) return;

        if (!confirm('Vuoi clonare questo articolo incrementando il codice?')) {
            return;
        }

        $.ajax({
            url: BSN_API.root + 'articoli/clone',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: { id: id },
            success: function(response) {
                alert('Articolo clonato. Nuovo ID: ' + response.id + (response.codice ? ' (codice: ' + response.codice + ')' : ''));
                bsnCaricaArticoli();
            },
            error: function(err) {
                console.error(err);
                var msg = 'Errore nella clonazione articolo.';
                if (err && err.responseJSON && err.responseJSON.message) {
                    msg += '\n' + err.responseJSON.message;
                }
                alert(msg);
            }
        });
    });

    // Elimina articolo
    $(document).on('click', '.bsn-articolo-delete', function() {
        var $row = $(this).closest('tr');
        var id   = $row.data('id');

        if (!id) return;

        if (!confirm('Vuoi eliminare definitivamente questo articolo?')) {
            return;
        }

        $.ajax({
            url: BSN_API.root + 'articoli/delete',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: { id: id },
            success: function(response) {
                alert('Articolo eliminato.');
                bsnCaricaArticoli();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nell\'eliminazione articolo.');
            }
        });
    });

    /* ==== NOLEGGI ==== */

    function bsnResetNoleggioKits() {
        BSN_NOLEGGIO_KITS = [];
        BSN_KIT_AVAILABILITY_MAP = {};
        $('#bsn-preview-kit-componenti').empty();
    }

    function bsnGetKitByArticoloId(articoloId) {
        return BSN_KIT_MAP_BY_ARTICOLO[parseInt(articoloId, 10)] || null;
    }

    function bsnGetNoleggioArticoliIds() {
        var ids = [];
        $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row').each(function() {
            var id = parseInt($(this).find('.bsn-noleggio-articolo-id').val(), 10);
            if (id) {
                ids.push(id);
            }
        });
        return ids;
    }

    function bsnGetKitInstanceById(instanceId) {
        return BSN_NOLEGGIO_KITS.find(function(k) {
            return k.instance_id === instanceId;
        });
    }

    function bsnBuildKitOptionsMap(kit) {
        var map = {};
        (kit.components || []).forEach(function(comp) {
            var ruolo = comp.ruolo || '';
            var gruppo = comp.gruppo_equivalenza || '';
            if (!map[ruolo]) {
                map[ruolo] = {};
            }
            if (!map[ruolo][gruppo]) {
                map[ruolo][gruppo] = [];
            }
            map[ruolo][gruppo].push(parseInt(comp.articolo_id, 10));
        });
        return map;
    }

    function bsnCreateKitInstance(kit) {
        var instanceId = 'kit-' + (++BSN_KIT_INSTANCE_COUNTER);
        var optionsMap = bsnBuildKitOptionsMap(kit);
        var components = [];

        var defaults = (kit.components || []).filter(function(comp) {
            return parseInt(comp.is_default, 10) === 1;
        });

        if (!defaults.length) {
            var ruoliUsati = {};
            (kit.components || []).forEach(function(comp) {
                if (!ruoliUsati[comp.ruolo]) {
                    defaults.push(comp);
                    ruoliUsati[comp.ruolo] = true;
                }
            });
        }

        defaults.forEach(function(comp) {
            var ruolo = comp.ruolo || '';
            var gruppo = comp.gruppo_equivalenza || '';
            var options = (optionsMap[ruolo] && optionsMap[ruolo][gruppo]) ? optionsMap[ruolo][gruppo] : [];
            components.push({
                ruolo: ruolo,
                gruppo_equivalenza: gruppo,
                qty: parseInt(comp.qty, 10) || 1,
                articolo_id: parseInt(comp.articolo_id, 10),
                is_selectable: parseInt(comp.is_selectable, 10) === 1,
                options: options
            });
        });

        return {
            instance_id: instanceId,
            kit_id: parseInt(kit.id, 10),
            kit_label: kit.nome_kit || '',
            articolo_kit_id: parseInt(kit.articolo_kit_id, 10),
            components: components
        };
    }

    function bsnAttachKitToRow($row, kit) {
        var existingId = $row.data('kit-instance-id');
        if (existingId) {
            bsnRemoveKitInstance(existingId);
        }

        var instance = bsnCreateKitInstance(kit);
        $row.data('kit-instance-id', instance.instance_id);
        BSN_NOLEGGIO_KITS.push(instance);
        bsnRenderKitComponenti();
        bsnScheduleKitAvailability();
    }

    function bsnRemoveKitInstance(instanceId) {
        BSN_NOLEGGIO_KITS = BSN_NOLEGGIO_KITS.filter(function(k) {
            return k.instance_id !== instanceId;
        });
        bsnRenderKitComponenti();
        bsnScheduleKitAvailability();
    }

    function bsnIsArticoloSelezionatoAltrove(articoloId, currentInstanceId, currentIndex) {
        var id = parseInt(articoloId, 10);
        if (!id) {
            return false;
        }

        var articoliNoleggio = bsnGetNoleggioArticoliIds();
        if (articoliNoleggio.indexOf(id) !== -1) {
            return true;
        }

        var trovato = false;
        BSN_NOLEGGIO_KITS.forEach(function(kit) {
            kit.components.forEach(function(comp, index) {
                if (kit.instance_id === currentInstanceId && index === currentIndex) {
                    return;
                }
                if (parseInt(comp.articolo_id, 10) === id) {
                    trovato = true;
                }
            });
        });
        return trovato;
    }

    function bsnScheduleKitAvailability() {
        if (BSN_KIT_AVAILABILITY_TIMER) {
            clearTimeout(BSN_KIT_AVAILABILITY_TIMER);
        }
        BSN_KIT_AVAILABILITY_TIMER = setTimeout(bsnUpdateKitAvailability, 450);
    }

    function bsnUpdateKitAvailability() {
        if (!BSN_NOLEGGIO_KITS.length) {
            BSN_KIT_AVAILABILITY_MAP = {};
            bsnRenderKitComponenti();
            return;
        }

        var periodoLogistico = bsnGetPeriodoLogisticoForm();
        if (!periodoLogistico.data_inizio || !periodoLogistico.data_fine) {
            BSN_KIT_AVAILABILITY_MAP = {};
            bsnRenderKitComponenti();
            return;
        }
        var tsInizio = new Date(periodoLogistico.data_inizio + 'T00:00:00').getTime();
        var tsFine = new Date(periodoLogistico.data_fine + 'T00:00:00').getTime();
        if (tsInizio > tsFine) {
            BSN_KIT_AVAILABILITY_MAP = {};
            bsnRenderKitComponenti();
            return;
        }

        var items = [];
        var candidateMap = {};
        BSN_NOLEGGIO_KITS.forEach(function(kit) {
            kit.components.forEach(function(comp) {
                var qty = parseInt(comp.qty, 10) || 1;
                (comp.options || []).forEach(function(optId) {
                    var id = parseInt(optId, 10);
                    if (!candidateMap[id] || candidateMap[id] < qty) {
                        candidateMap[id] = qty;
                    }
                });
            });
        });

        Object.keys(candidateMap).forEach(function(id) {
            items.push({ articolo_id: parseInt(id, 10), qty: candidateMap[id] });
        });

        if (!items.length) {
            BSN_KIT_AVAILABILITY_MAP = {};
            bsnRenderKitComponenti();
            return;
        }

        $.ajax({
            url: BSN_API.root + 'noleggi/alert-stato',
            method: 'POST',
            data: {
                data_inizio: periodoLogistico.data_inizio,
                data_fine: periodoLogistico.data_fine,
                items: items,
                exclude_noleggio_id: $('#bsn-noleggio-id').val() || ''
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                BSN_KIT_AVAILABILITY_MAP = response || {};
                bsnRenderKitComponenti();
            },
            error: function() {
                BSN_KIT_AVAILABILITY_MAP = {};
                bsnRenderKitComponenti();
            }
        });
    }

    function bsnRenderKitComponenti() {
        var $container = $('#bsn-preview-kit-componenti');
        if (!$container.length) {
            return;
        }

        if (!BSN_NOLEGGIO_KITS.length) {
            $container.html('');
            return;
        }

        var html = '<div style="border:1px solid #ddd; border-radius:6px; padding:10px; background:#fff;">' +
            '<strong>Componenti Kit</strong>';

        BSN_NOLEGGIO_KITS.forEach(function(kit) {
            html += '<div style="margin-top:10px;">' +
                '<div style="font-weight:bold; margin-bottom:6px;">' + (kit.kit_label || 'Kit') + '</div>' +
                '<table style="width:100%; border-collapse:collapse; font-size:12px;">' +
                    '<thead><tr style="background:#f3f3f3;">' +
                        '<th style="text-align:left; padding:4px; border:1px solid #ddd;">Ruolo</th>' +
                        '<th style="text-align:center; padding:4px; border:1px solid #ddd;">Qty</th>' +
                        '<th style="text-align:left; padding:4px; border:1px solid #ddd;">Articolo assegnato</th>' +
                    '</tr></thead><tbody>';

            kit.components.forEach(function(comp, index) {
                var articolo = BSN_ARTICOLI_CACHE.find(function(a) {
                    return parseInt(a.id, 10) === parseInt(comp.articolo_id, 10);
                });
                var label = '';
                if (articolo && articolo.codice) {
                    label += '[' + articolo.codice + '] ';
                }
                label += articolo ? articolo.nome : ('Articolo #' + comp.articolo_id);

                var isDuplicate = bsnIsArticoloSelezionatoAltrove(comp.articolo_id, kit.instance_id, index);
                var availability = BSN_KIT_AVAILABILITY_MAP[comp.articolo_id];
                var isAvailable = availability ? availability.is_available : true;

                html += '<tr>' +
                    '<td style="padding:4px; border:1px solid #ddd;">' + (comp.ruolo || '-') + '</td>' +
                    '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + (comp.qty || 1) + '</td>' +
                    '<td style="padding:4px; border:1px solid #ddd;">';

                if (comp.is_selectable) {
                    html += '<select class="bsn-kit-component-select" data-kit-instance-id="' + kit.instance_id + '" data-component-index="' + index + '" style="width:100%;">';
                    (comp.options || []).forEach(function(optId) {
                        var art = BSN_ARTICOLI_CACHE.find(function(a) {
                            return parseInt(a.id, 10) === parseInt(optId, 10);
                        });
                        var optionLabel = '';
                        if (art && art.codice) {
                            optionLabel += '[' + art.codice + '] ';
                        }
                        optionLabel += art ? art.nome : ('Articolo #' + optId);

                        var duplicate = bsnIsArticoloSelezionatoAltrove(optId, kit.instance_id, index);
                        var avail = BSN_KIT_AVAILABILITY_MAP[optId];
                        var available = avail ? avail.is_available : true;
                        var disabled = (duplicate || !available) ? ' disabled' : '';

                        var selected = parseInt(optId, 10) === parseInt(comp.articolo_id, 10) ? ' selected' : '';
                        html += '<option value="' + optId + '"' + selected + disabled + '>' + optionLabel + '</option>';
                    });
                    html += '</select>';
                } else {
                    html += label;
                }

                if (!isAvailable || isDuplicate) {
                    html += '<div style="font-size:11px; color:#b00; margin-top:4px;">' +
                        (isDuplicate ? 'Articolo già usato nel noleggio.' : 'Non disponibile nelle date selezionate.') +
                        '</div>';
                }

                html += '</td></tr>';
            });

            html += '</tbody></table></div>';
        });

        html += '</div>';
        $container.html(html);
    }

    $(document).on('change', '.bsn-kit-component-select', function() {
        var instanceId = $(this).data('kit-instance-id');
        var componentIndex = parseInt($(this).data('component-index'), 10);
        var selectedId = parseInt($(this).val(), 10);

        var kit = bsnGetKitInstanceById(instanceId);
        if (kit && kit.components[componentIndex]) {
            kit.components[componentIndex].articolo_id = selectedId;
            bsnRenderKitComponenti();
            bsnScheduleKitAvailability();
            bsnCalcolaTotaleNoleggio();
        }
    });

    function bsnSerializeKitsForSave() {
        return BSN_NOLEGGIO_KITS.map(function(kit) {
            return {
                kit_id: kit.kit_id,
                kit_label: kit.kit_label,
                articolo_kit_id: kit.articolo_kit_id,
                components: kit.components.map(function(comp) {
                    return {
                        ruolo: comp.ruolo,
                        gruppo_equivalenza: comp.gruppo_equivalenza,
                        articolo_id: comp.articolo_id,
                        qty: comp.qty
                    };
                })
            };
        });
    }

    /* ==== FUNZIONE CALCOLO TOTALE NOLEGGIO (preview) ==== */

    /**
     * Calcola il totale noleggio in JS con la stessa logica di PHP:
     * - articoli scalari: prezzo_netto * qty * sqrt(giorni)
     * - articoli non scalari: prezzo_netto * qty
     * - regime fiscale applicato al subtotale
     */
    /* ==== FUNZIONE CALCOLO TOTALE NOLEGGIO (preview) ==== */

    function bsnGetPeriodoLogisticoForm() {
        var dataInizioNoleggio = $('#bsn-form-noleggio input[name="data_inizio"]').val() || '';
        var dataFineNoleggio = $('#bsn-form-noleggio input[name="data_fine"]').val() || '';
        var dataRitiro = $('#bsn-form-noleggio input[name="data_ritiro"]').val() || '';
        var dataRiconsegna = $('#bsn-form-noleggio input[name="data_riconsegna"]').val() || '';

        return {
            data_inizio_noleggio: dataInizioNoleggio,
            data_fine_noleggio: dataFineNoleggio,
            data_ritiro: dataRitiro,
            data_riconsegna: dataRiconsegna,
            data_inizio: dataRitiro || dataInizioNoleggio,
            data_fine: dataRiconsegna || dataFineNoleggio
        };
    }

    function bsnSyncLogisticDatesFromUsage() {
        var $form = $('#bsn-form-noleggio');
        var prevStart = $form.data('bsnLastDataInizio') || '';
        var prevEnd = $form.data('bsnLastDataFine') || '';
        var currentStart = $form.find('input[name="data_inizio"]').val() || '';
        var currentEnd = $form.find('input[name="data_fine"]').val() || '';
        var $ritiro = $form.find('input[name="data_ritiro"]');
        var $riconsegna = $form.find('input[name="data_riconsegna"]');
        var currentRitiro = $ritiro.val() || '';
        var currentRiconsegna = $riconsegna.val() || '';

        if (currentStart && (!currentRitiro || currentRitiro === prevStart)) {
            $ritiro.val(currentStart);
        }
        if (currentEnd && (!currentRiconsegna || currentRiconsegna === prevEnd)) {
            $riconsegna.val(currentEnd);
        }

        $form.data('bsnLastDataInizio', currentStart);
        $form.data('bsnLastDataFine', currentEnd);
    }

    function bsnHidePreviewAndAlerts() {
        $('#bsn-noleggio-preview').hide();
        $('#bsn-preview-kit-componenti').empty();
        bsnClearPreviewAlerts();
    }

    var bsnPreviewArticoliFetchInFlight = false;
    var bsnPreviewArticoliTried = {};
    var bsnPreviewClienteFetchInFlight = false;
    var bsnPreviewClienteLastTriedId = null;

    function bsnFetchClienteByIdForPreview(id, done) {
        $.ajax({
            url: BSN_API.root + 'clienti/search',
            method: 'GET',
            data: { id: id, limit: 1 },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var items = (response && response.clienti) ? response.clienti : [];
                var exact = Array.isArray(items) && items.length ? items[0] : null;
                done(exact);
            },
            error: function() {
                done(null);
            }
        });
    }

    function bsnFetchArticoloByIdForPreview(id, done) {
        $.ajax({
            url: BSN_API.root + 'articoli/search',
            method: 'GET',
            data: { id: id, limit: 1 },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var items = (response && response.articoli) ? response.articoli : [];
                var exact = Array.isArray(items) && items.length ? items[0] : null;
                done(exact);
            },
            error: function() {
                done(null);
            }
        });
    }

    function bsnEnsureArticoliForPreview(ids, done) {
        if (!ids.length) {
            done();
            return;
        }

        var pending = ids.length;
        ids.forEach(function(id) {
            bsnFetchArticoloByIdForPreview(id, function(art) {
                bsnPreviewArticoliTried[id] = true;
                if (art && art.id) {
                    var existing = (BSN_ARTICOLI_CACHE || []).some(function(x) {
                        return parseInt(x.id, 10) === parseInt(art.id, 10);
                    });
                    if (!existing) {
                        BSN_ARTICOLI_CACHE.push(art);
                    }
                }
                pending -= 1;
                if (pending === 0) {
                    done();
                }
            });
        });
    }

    function bsnEscHtml(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function bsnToFloat(value, fallback) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return fallback;
        }
        var num = parseFloat(String(value).replace(',', '.'));
        return isNaN(num) ? fallback : num;
    }

    function bsnClampPct(value) {
        var pct = bsnToFloat(value, 0);
        return Math.max(0, Math.min(100, pct));
    }

    function bsnGetCategoryDiscountPctJs(art, categoria) {
        switch (categoria) {
            case 'fidato':
                return bsnClampPct(art.sconto_fidato);
            case 'premium':
                return bsnClampPct(art.sconto_premium);
            case 'service':
                return bsnClampPct(art.sconto_service);
            case 'collaboratori':
                return bsnClampPct(art.sconto_collaboratori);
            case 'standard':
            default:
                return bsnClampPct(art.sconto_standard);
        }
    }

    function bsnGetQuantityDiscountConfigFromArticleJs(art) {
        art = art || {};
        var maxQtyRaw = art.qty_discount_max_qty;
        var maxQty = (maxQtyRaw === null || typeof maxQtyRaw === 'undefined' || maxQtyRaw === '')
            ? (parseInt(art.qty_disponibile, 10) || 0)
            : (parseInt(maxQtyRaw, 10) || 0);
        return {
            enabled: parseInt(art.qty_discount_enabled, 10) === 1,
            start_qty: Math.max(0, parseInt(art.qty_discount_start_qty, 10) || 0),
            max_qty: Math.max(0, maxQty),
            max_discount_pct: bsnClampPct(art.qty_discount_max_pct)
        };
    }

    function bsnCalculateLinearQuantityDiscountJs(baseUnitPrice, qty, config) {
        baseUnitPrice = Math.max(0, bsnToFloat(baseUnitPrice, 0));
        qty = Math.max(0, bsnToFloat(qty, 0));
        config = config || {};
        var discountPct = 0;

        if (
            config.enabled &&
            baseUnitPrice > 0 &&
            qty > 0 &&
            config.start_qty > 0 &&
            config.max_discount_pct > 0 &&
            qty >= config.start_qty
        ) {
            if (config.max_qty <= config.start_qty || qty >= config.max_qty) {
                discountPct = config.max_discount_pct;
            } else {
                discountPct = ((qty - config.start_qty) / (config.max_qty - config.start_qty)) * config.max_discount_pct;
            }
        }

        discountPct = bsnClampPct(discountPct);
        return {
            discount_pct: discountPct,
            unit_price: Math.max(0, baseUnitPrice * (1 - (discountPct / 100)))
        };
    }

    function bsnPickNoleggioExtraFields(source) {
        var keys = [
            'service_internal', 'service_component_label', 'service_component_code', 'service_mode',
            'public_product_id', 'public_product_title', 'public_product_permalink', 'public_product_image_url',
            'public_product_code', 'public_pricing_category', 'public_request_origin', 'calculation_type',
            'dimension_summary', 'selected_preset_label', 'dimension_note', 'public_min_qty',
            'modules_x', 'modules_y', 'modules_total', 'public_unit_price_net', 'public_line_total',
            'requested_width_m', 'requested_height_m', 'technical_width_m', 'technical_height_m',
            'area_mq', 'qty_discount_applied_pct', 'unit_price_before_qty_discount', 'unit_price_after_qty_discount'
        ];
        var out = {};
        source = source || {};
        keys.forEach(function(key) {
            if (Object.prototype.hasOwnProperty.call(source, key) && source[key] !== null && typeof source[key] !== 'undefined' && source[key] !== '') {
                out[key] = source[key];
            }
        });
        return out;
    }

    function bsnNormalizePriceForCompare(value) {
        var parsed = bsnToFloat(value, null);
        return parsed === null ? '' : parsed.toFixed(2);
    }

    function bsnRemovePricingSnapshotFields(extra) {
        var out = $.extend({}, extra || {});
        [
            'public_unit_price_net',
            'public_line_total',
            'qty_discount_applied_pct',
            'unit_price_before_qty_discount',
            'unit_price_after_qty_discount'
        ].forEach(function(key) {
            delete out[key];
        });
        return out;
    }

    function bsnBuildNoleggioLineFromRow($row, includeExtras) {
        var id = parseInt($row.find('.bsn-noleggio-articolo-id').val(), 10) || 0;
        var qty = parseInt($row.find('.bsn-noleggio-articolo-qty').val(), 10) || 0;
        var priceRaw = ($row.find('.bsn-noleggio-articolo-prezzo').val() || '').trim();
        var prezzo = priceRaw !== '' ? bsnToFloat(priceRaw, null) : null;
        var hasOriginal = typeof $row.data('bsnOriginalQty') !== 'undefined';
        var rowModified = true;
        var lineValueModified = true;
        var dateChanged = false;

        if (hasOriginal) {
            lineValueModified = parseInt($row.data('bsnOriginalQty'), 10) !== qty ||
                String($row.data('bsnOriginalPrezzo') || '') !== bsnNormalizePriceForCompare(priceRaw);
            rowModified = lineValueModified;
            var originalStart = $('#bsn-form-noleggio').data('bsnOriginalDataInizio');
            var originalEnd = $('#bsn-form-noleggio').data('bsnOriginalDataFine');
            if (typeof originalStart !== 'undefined' || typeof originalEnd !== 'undefined') {
                dateChanged =
                    String(originalStart || '') !== String($('#bsn-form-noleggio input[name="data_inizio"]').val() || '') ||
                    String(originalEnd || '') !== String($('#bsn-form-noleggio input[name="data_fine"]').val() || '');
                rowModified = rowModified || dateChanged;
            }
        }

        var line = {
            id: id,
            qty: qty,
            prezzo: prezzo,
            rowModified: rowModified
        };

        if (includeExtras) {
            if (!rowModified) {
                $.extend(line, $row.data('bsnExtra') || {});
            } else if (dateChanged && !lineValueModified) {
                $.extend(line, bsnRemovePricingSnapshotFields($row.data('bsnExtra') || {}));
            }
        }

        return line;
    }

    function bsnIsNoleggioRowSnapshotUsable(line) {
        return !!(
            line &&
            !line.rowModified &&
            line.public_unit_price_net !== null &&
            typeof line.public_unit_price_net !== 'undefined' &&
            line.public_unit_price_net !== ''
        );
    }

    function bsnCalculateNoleggioLinePriceJs(line, art, categoria, giorni) {
        var qty = Math.max(1, parseInt(line.qty, 10) || 1);
        var basePrice = bsnToFloat(art.prezzo_giorno, 0);
        var prezzo = line.prezzo !== null && typeof line.prezzo !== 'undefined' ? bsnToFloat(line.prezzo, basePrice) : basePrice;
        var noleggioScalare = parseInt(art.noleggio_scalare, 10) === 1;
        var factor = noleggioScalare ? Math.sqrt(Math.max(1, giorni)) : 1;

        if (bsnIsNoleggioRowSnapshotUsable(line)) {
            var unitAfterSnapshot = bsnToFloat(line.public_unit_price_net, 0);
            var lineTotalSnapshot = bsnToFloat(line.public_line_total, null);
            var unitBeforeSnapshot = bsnToFloat(line.unit_price_before_qty_discount, unitAfterSnapshot);
            var qtyDiscountSnapshot = bsnToFloat(line.qty_discount_applied_pct, null);
            if (qtyDiscountSnapshot === null && unitBeforeSnapshot > 0) {
                qtyDiscountSnapshot = (1 - (unitAfterSnapshot / unitBeforeSnapshot)) * 100;
            }
            var categoryDiscountSnapshot = 0;
            if (prezzo > 0 && unitBeforeSnapshot >= 0 && unitBeforeSnapshot <= prezzo) {
                categoryDiscountSnapshot = (1 - (unitBeforeSnapshot / prezzo)) * 100;
            }

            return {
                prezzo: prezzo > 0 ? prezzo : unitAfterSnapshot,
                categoryDiscountPct: bsnClampPct(categoryDiscountSnapshot),
                qtyDiscountPct: bsnClampPct(qtyDiscountSnapshot),
                unitPriceBeforeQtyDiscount: unitBeforeSnapshot,
                unitPriceAfterQtyDiscount: unitAfterSnapshot,
                lineTotal: lineTotalSnapshot !== null ? lineTotalSnapshot : (unitAfterSnapshot * qty * factor),
                noleggioScalare: noleggioScalare
            };
        }

        var categoryDiscount = bsnGetCategoryDiscountPctJs(art, categoria);
        var unitBeforeQtyDiscount = prezzo * (1 - (categoryDiscount / 100));
        var qtyDiscount = bsnCalculateLinearQuantityDiscountJs(
            unitBeforeQtyDiscount,
            qty,
            bsnGetQuantityDiscountConfigFromArticleJs(art)
        );

        return {
            prezzo: prezzo,
            categoryDiscountPct: categoryDiscount,
            qtyDiscountPct: bsnClampPct(qtyDiscount.discount_pct),
            unitPriceBeforeQtyDiscount: unitBeforeQtyDiscount,
            unitPriceAfterQtyDiscount: qtyDiscount.unit_price,
            lineTotal: qtyDiscount.unit_price * qty * factor,
            noleggioScalare: noleggioScalare
        };
    }
    
    function bsnCalcolaTotaleNoleggio() {
        // 1. Recupera cliente_id
        var clienteId = $('#bsn-noleggio-cliente-id').val();
        if (!clienteId) {
            bsnHidePreviewAndAlerts();
            return;
        }

        // Trova il cliente in cache
        var cliente = BSN_CLIENTI_CACHE.find(function(c) {
            return parseInt(c.id, 10) === parseInt(clienteId, 10);
        });

        if (!cliente) {
            var clienteInt = parseInt(clienteId, 10);
            if (clienteInt && !bsnPreviewClienteFetchInFlight && bsnPreviewClienteLastTriedId !== clienteInt) {
                bsnPreviewClienteFetchInFlight = true;
                bsnPreviewClienteLastTriedId = clienteInt;
                bsnFetchClienteByIdForPreview(clienteInt, function(found) {
                    bsnPreviewClienteFetchInFlight = false;
                    if (found && found.id) {
                        var exists = (BSN_CLIENTI_CACHE || []).some(function(c) {
                            return parseInt(c.id, 10) === parseInt(found.id, 10);
                        });
                        if (!exists) {
                            BSN_CLIENTI_CACHE.push(found);
                        }
                        bsnCalcolaTotaleNoleggio();
                        return;
                    }
                    bsnHidePreviewAndAlerts();
                });
                return;
            }
            bsnHidePreviewAndAlerts();
            return;
        }

        // 2. Recupera date e calcola giorni
        var dataInizio = $('#bsn-form-noleggio input[name="data_inizio"]').val();
        var dataFine   = $('#bsn-form-noleggio input[name="data_fine"]').val();

        if (!dataInizio || !dataFine) {
            bsnHidePreviewAndAlerts();
            return;
        }

        var tsInizio = new Date(dataInizio + 'T00:00:00').getTime();
        var tsFine   = new Date(dataFine + 'T00:00:00').getTime();

        if (tsInizio > tsFine) {
            bsnHidePreviewAndAlerts();
            return;
        }

        var giorni = Math.max(1, Math.ceil((tsFine - tsInizio) / (1000 * 86400)));
        var fattoreGiorni = Math.sqrt(giorni);

        // 3. Raccogli articoli dal form
        var articoli = [];
        $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row').each(function() {
            var line = bsnBuildNoleggioLineFromRow($(this), true);

            if (line.id && line.qty > 0) {
                articoli.push(line);
            }
        });

        if (articoli.length === 0) {
            bsnHidePreviewAndAlerts();
            return;
        }

// Se mancano articoli in cache (es. molti inserimenti consecutivi), recuperali prima del calcolo.
var missingIds = [];
articoli.forEach(function(a) {
    var exists = (BSN_ARTICOLI_CACHE || []).some(function(x) {
        return parseInt(x.id, 10) === parseInt(a.id, 10);
    });
    if (!exists && !bsnPreviewArticoliTried[a.id]) {
        missingIds.push(a.id);
    }
});

if (missingIds.length && !bsnPreviewArticoliFetchInFlight) {
    bsnPreviewArticoliFetchInFlight = true;
    bsnEnsureArticoliForPreview(missingIds, function() {
        bsnPreviewArticoliFetchInFlight = false;
        bsnCalcolaTotaleNoleggio();
    });
    return;
}

if (missingIds.length && bsnPreviewArticoliFetchInFlight) {
    return;
}


        // 4. Popola intestazione cliente
        $('#bsn-preview-cliente-nome').text(cliente.nome || '-');
        $('#bsn-preview-cliente-cf').text(cliente.cf_piva || '-');
        $('#bsn-preview-cliente-categoria').text((cliente.categoria_cliente || 'standard').toUpperCase());

        // Note cliente
        if (cliente.note) {
            $('#bsn-preview-cliente-note').text(cliente.note);
            $('#bsn-preview-note-cliente').show();
        } else {
            $('#bsn-preview-note-cliente').hide();
        }

        var periodoLogistico = bsnGetPeriodoLogisticoForm();

        // Date in formato italiano
        var dataInizioIT = bsnConvertiDataPerPrintStr(dataInizio);
        var dataFineIT   = bsnConvertiDataPerPrintStr(dataFine);
        var dataRitiroIT = bsnConvertiDataPerPrintStr(periodoLogistico.data_inizio);
        var dataRiconsegnaIT = bsnConvertiDataPerPrintStr(periodoLogistico.data_fine);

        $('#bsn-preview-data-da').text(dataInizioIT);
        $('#bsn-preview-data-a').text(dataFineIT);
        $('#bsn-preview-data-ritiro').text(dataRitiroIT);
        $('#bsn-preview-data-riconsegna').text(dataRiconsegnaIT);
        $('#bsn-preview-giorni').text(giorni);

        // Popola dati logistica/trasporto
        var luogoDest = $('#bsn-form-noleggio input[name="luogo_destinazione"]').val() || '';
        var trasporto = $('#bsn-form-noleggio input[name="trasporto_mezzo"]').val() || '';
        var cauzione = $('#bsn-form-noleggio input[name="cauzione"]').val() || '';
        var causale = $('#bsn-form-noleggio input[name="causale_trasporto"]').val() || '';

        if (luogoDest || trasporto || cauzione || causale) {
            $('#bsn-preview-luogo-dest').text(luogoDest || '-');
            $('#bsn-preview-trasporto').text(trasporto || '-');
            $('#bsn-preview-cauzione').text(cauzione || '-');
            $('#bsn-preview-causale').text(causale || '-');
            $('#bsn-preview-logistica').show();
        } else {
            $('#bsn-preview-logistica').hide();
        }

        // Regime
        var regime = parseFloat(cliente.regime_percentuale) || 0.0;
        $('#bsn-preview-regime-perc').text(regime.toFixed(2));

        // 5. Calcola articoli e popola tabella
        var categoria = cliente.categoria_cliente || 'standard';
        var subtotale = 0.0;
        var htmlTabella = '';
        var htmlDettagli = '';

        articoli.forEach(function(a) {
            var art = BSN_ARTICOLI_CACHE.find(function(x) {
                return parseInt(x.id, 10) === parseInt(a.id, 10);
            });

            if (!art) {
                return;
            }

            // Usa prezzo custom se presente, altrimenti prezzo base articolo
            var prezzo = (a.prezzo !== null && a.prezzo !== undefined) ? a.prezzo : (parseFloat(art.prezzo_giorno) || 0);
            var qty = a.qty;


            // Sconto categoria
            var sconto = 0;
            switch (categoria) {
                case 'fidato':
                    sconto = parseFloat(art.sconto_fidato) || 0;
                    break;
                case 'premium':
                    sconto = parseFloat(art.sconto_premium) || 0;
                    break;
                case 'service':
                    sconto = parseFloat(art.sconto_service) || 0;
                    break;
                case 'collaboratori':
                    sconto = parseFloat(art.sconto_collaboratori) || 0;
                    break;
                case 'standard':
                default:
                    sconto = parseFloat(art.sconto_standard) || 0;
                    break;
            }

            var prezzoNetto = prezzo * (1 - (Math.max(0, Math.min(100, sconto)) / 100));

            // Fattore giorni
            var noleggio_scalare = parseInt(art.noleggio_scalare, 10);
            var fattore = '';
            var subtotalArticolo = 0;

            if (noleggio_scalare === 1) {
                fattore = '√' + giorni + ' (' + fattoreGiorni.toFixed(3) + ')';
                subtotalArticolo = prezzoNetto * qty * fattoreGiorni;
            } else {
                fattore = 'Lineare';
                subtotalArticolo = prezzoNetto * qty;
            }

            var linePrice = bsnCalculateNoleggioLinePriceJs(a, art, categoria, giorni);
            prezzo = linePrice.prezzo;
            sconto = linePrice.categoryDiscountPct;
            var qtyDiscountPct = linePrice.qtyDiscountPct;
            subtotalArticolo = linePrice.lineTotal;
            fattore = linePrice.noleggioScalare
                ? '√' + giorni + ' (' + fattoreGiorni.toFixed(3) + ')'
                : (giorni > 1 ? 'Lineare' : '1 giorno');
            var scontoHtml = '-' + sconto.toFixed(2).replace('.', ',') + '%';
            if (qtyDiscountPct > 0.0001) {
                scontoHtml = 'Cat -' + sconto.toFixed(2).replace('.', ',') + '%<br>Qty -' + qtyDiscountPct.toFixed(2).replace('.', ',') + '%';
            }
            var productNameHtml = '[' + bsnEscHtml(art.codice || '') + '] ' + bsnEscHtml(art.nome || '');
            if (a.dimension_summary) {
                productNameHtml += '<div style="margin-top:2px; color:#666; font-size:11px;">' + bsnEscHtml(a.dimension_summary) + '</div>';
            }

            subtotale += subtotalArticolo;

            // Ubicazione
            var ubicazione = art.ubicazione || '-';

            // Correlati (moltiplicati per qty articolo noleggiato)
            var correlatiTesto = '-';
            if (art.correlati) {
                try {
                    var correlati = JSON.parse(art.correlati);
                    if (Array.isArray(correlati) && correlati.length > 0) {
                        var corrLabels = correlati.map(function(c) {
                            var qtyCorrelato = (c.qty || 1) * qty; // MOLTIPLICA per qty articolo
                            return qtyCorrelato + 'x ' + c.nome;
                        });
                        correlatiTesto = corrLabels.join(', ');
                    }
                } catch (e) {
                    // JSON parse fallito
                }
            }

            // Riga tabella
            htmlTabella += '<tr data-articolo-id="' + art.id + '">' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:left;">' +
                    productNameHtml +
                '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:center;">€ ' + prezzo.toFixed(2).replace('.', ',') + '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:center;">' + qty + '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:center;">' + bsnEscHtml(fattore) + '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:center;">' + scontoHtml + '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:right;">€ ' + subtotalArticolo.toFixed(2).replace('.', ',') + '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:left;">' + bsnEscHtml(ubicazione) + '</td>' +
                '<td style="border:1px solid #ccc; padding:5px; text-align:left;">' + bsnEscHtml(correlatiTesto) + '</td>' +
                '<td class="bsn-preview-stato-cell" style="border:1px solid #ccc; padding:5px; text-align:center;" data-articolo-id="' + art.id + '"></td>' +
                '</tr>';
        });

        // Popola tabella
        $('#bsn-preview-articoli-tbody').html(htmlTabella);
        $('#bsn-preview-dettagli-articoli').html(htmlDettagli);
        bsnRenderKitComponenti();
        bsnScheduleKitAvailability();

        // 6. Calcola totale con regime
        var totaleConRegime = subtotale;
        var importoRegime = 0;

        if (regime > 0) {
            importoRegime = subtotale * (regime / 100);
            totaleConRegime = subtotale + importoRegime;
        }

        // 7. Sconto globale
        var scontoGlobale = parseFloat($('#bsn-noleggio-sconto-globale').val()) || 0;
        var totaleFinale = totaleConRegime + scontoGlobale;

        // Popola totali
        $('#bsn-preview-subtotale').text('€ ' + subtotale.toFixed(2).replace('.', ','));
        $('#bsn-preview-regime-importo').text('€ ' + importoRegime.toFixed(2).replace('.', ','));
        $('#bsn-preview-totale').text('€ ' + totaleConRegime.toFixed(2).replace('.', ','));

        // Mostra sconto solo se valorizzato
        if (scontoGlobale !== 0) {
            $('#bsn-preview-sconto').text('€ ' + scontoGlobale.toFixed(2).replace('.', ','));
            $('#bsn-preview-sconto-block').show();
        } else {
            $('#bsn-preview-sconto-block').hide();
        }

        $('#bsn-preview-totale-finale').text('€ ' + totaleFinale.toFixed(2).replace('.', ','));

        // Mostra la preview
        $('#bsn-noleggio-preview').show();

        // Aggiorna stato disponibilità/ticket (debounced)
        bsnSchedulePreviewAlerts();
    }

    // Helper per convertire data YYYY-MM-DD in DD-MM-YYYY
    function bsnConvertiDataPerPrintStr(val) {
        if (!val) return '-';
        var parti = val.split('-');
        if (parti.length !== 3) return '-';
        return parti[2] + '-' + parti[1] + '-' + parti[0];
    }

    function bsnFormatEuroAmount(val) {
        var num = parseFloat(val);
        if (isNaN(num)) return '-';
        return 'EUR ' + num.toLocaleString('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    var bsnPreviewAlertTimer = null;

    function bsnSchedulePreviewAlerts() {
        if (bsnPreviewAlertTimer) {
            clearTimeout(bsnPreviewAlertTimer);
        }
        bsnPreviewAlertTimer = setTimeout(bsnUpdatePreviewAlerts, 450);
    }

    function bsnClearPreviewAlerts() {
        $('#bsn-preview-articoli-tbody .bsn-preview-stato-cell')
            .empty()
            .removeAttr('title');
    }

    function bsnUpdatePreviewAlerts() {
        var periodoLogistico = bsnGetPeriodoLogisticoForm();
        var dataInizio = periodoLogistico.data_inizio;
        var dataFine = periodoLogistico.data_fine;

        if (!dataInizio || !dataFine) {
            bsnClearPreviewAlerts();
            return;
        }

        var tsInizio = new Date(dataInizio + 'T00:00:00').getTime();
        var tsFine = new Date(dataFine + 'T00:00:00').getTime();
        if (tsInizio > tsFine) {
            bsnClearPreviewAlerts();
            return;
        }

        var items = [];
        $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row').each(function() {
            var id = parseInt($(this).find('.bsn-noleggio-articolo-id').val(), 10);
            var qty = parseInt($(this).find('.bsn-noleggio-articolo-qty').val(), 10) || 0;
            if (id && qty > 0) {
                items.push({ articolo_id: id, qty: qty });
            }
        });

        BSN_NOLEGGIO_KITS.forEach(function(kit) {
            kit.components.forEach(function(comp) {
                var id = parseInt(comp.articolo_id, 10);
                var qty = parseInt(comp.qty, 10) || 0;
                if (id && qty > 0) {
                    items.push({ articolo_id: id, qty: qty });
                }
            });
        });

        if (!items.length) {
            bsnClearPreviewAlerts();
            return;
        }

        $.ajax({
            url: BSN_API.root + 'noleggi/alert-stato',
            method: 'POST',
            data: {
                data_inizio: dataInizio,
                data_fine: dataFine,
                items: items,
                exclude_noleggio_id: $('#bsn-noleggio-id').val() || ''
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                $('#bsn-preview-articoli-tbody .bsn-preview-stato-cell').each(function() {
                    var $cell = $(this);
                    var articoloId = parseInt($cell.data('articolo-id'), 10);
                    var info = response && response[articoloId] ? response[articoloId] : null;
                    var html = '';
                    var titleParts = [];

                    if (info && info.is_available === false) {
                        var conflictId = info.first_conflict_noleggio_id || '';
                        var conflictLabel = conflictId ? (' ' + conflictId) : '';
                        html += '<span class="bsn-alert-icon bsn-alert-availability">⚠' + conflictLabel + '</span>';
                        titleParts.push('Non disponibile: conflitto con ' + (conflictId || 'noleggio sovrapposto'));
                    }

                    if (info && info.has_open_ticket) {
                        html += '<span class="bsn-alert-icon bsn-alert-ticket">🎫</span>';
                        titleParts.push('Ticket aperto su questo articolo');
                    }

                    $cell.html(html);
                    if (titleParts.length) {
                        $cell.attr('title', titleParts.join(' | '));
                    } else {
                        $cell.removeAttr('title');
                    }
                });
            },
            error: function() {
                bsnClearPreviewAlerts();
            }
        });
    }

    // Aggiungi riga articolo nel form noleggio
    $('#bsn-noleggio-articolo-add').on('click', function() {
        var $wrapper = $('#bsn-noleggio-articoli-wrapper');

        var $row = $(
            '<div class="bsn-noleggio-articolo-row">' +
            '<input type="text" class="bsn-noleggio-articolo-search" placeholder="Cerca articolo..." autocomplete="off" style="flex:1 1 200px; min-width:150px;">' +
            '<input type="hidden" class="bsn-noleggio-articolo-id" name="articoli_id[]">' +
            '<input type="number" class="bsn-noleggio-articolo-prezzo" name="articoli_prezzo[]" min="0" step="0.01" placeholder="Prezzo" style="max-width:90px;">' +
            '<input type="number" class="bsn-noleggio-articolo-qty" name="articoli_qty[]" min="1" value="1" style="max-width:60px;">' +
            '<button type="button" class="btn bsn-noleggio-articolo-remove">X</button>' +
            '<div class="bsn-noleggio-articoli-risultati" ' +
                 'style="border:1px solid #ccc; max-height:150px; overflow-y:auto; display:none; ' +
                        'background:#fff; position:relative; z-index:10;"></div>' +
            '</div>'
        );

        $wrapper.append($row);

        // Aggiorna preview
        bsnCalcolaTotaleNoleggio();
    });

    // Rimuovi riga articolo nel form noleggio
    $(document).on('click', '.bsn-noleggio-articolo-remove', function() {
        var $row = $(this).closest('.bsn-noleggio-articolo-row');
        var kitInstanceId = $row.data('kit-instance-id');
        if (kitInstanceId) {
            bsnRemoveKitInstance(kitInstanceId);
        }
        $row.remove();

        // Aggiorna preview
        bsnCalcolaTotaleNoleggio();
    });

    // Ricerca articoli per ogni riga (server-side, indipendente dalla cache tabella)
    function bsnMergeArticoliCache(articoli) {
        if (!Array.isArray(articoli) || !articoli.length) return;
        var map = {};
        (BSN_ARTICOLI_CACHE || []).forEach(function(a) {
            if (a && a.id) map[parseInt(a.id, 10)] = a;
        });
        articoli.forEach(function(a) {
            if (a && a.id) map[parseInt(a.id, 10)] = a;
        });
        BSN_ARTICOLI_CACHE = Object.keys(map).map(function(id) { return map[id]; });
    }

         function bsnSearchArticoliNoleggio(query, done) {
        $.ajax({
            url: BSN_API.root + 'articoli/search',
            method: 'GET',
            data: { search: query, limit: 15 },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var items = (response && response.articoli) ? response.articoli : [];
                bsnMergeArticoliCache(items);
                done(items);
            },
            error: function() {
                done([]);
            }
        });
        }

        function bsnRenderRisultatiArticoliNoleggio($row, risultati) {
        var $box = $row.find('.bsn-noleggio-articoli-risultati');
        $box.empty();

        if (!risultati.length) {
            bsnResetSearchDropdownActive($box, '.bsn-ticket-risultato-articolo');
            $box.append('<div style="padding:5px;">Nessun articolo trovato.</div>');
            $box.show();
            return;
        }

        risultati.slice(0, 15).forEach(function(a) {
            var label = '';
            if (a.codice) {
                label += '[' + a.codice + '] ';
            }
            label += (a.nome || ('Articolo #' + a.id));

            var html = '' +
                '<div class="bsn-risultato-articolo" data-id="' + a.id + '" data-prezzo="' + (a.prezzo_giorno || '0') + '" data-codice="' + (a.codice || '') + '" data-nome="' + (a.nome || '') + '" ' +
                     'style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">' +
                    label +
                '</div>';
            $box.append(html);
        });

        $box.show();
        bsnSetSearchDropdownActive($box, '.bsn-ticket-risultato-articolo', 0);
    }

    function bsnCleanupArticoloSearchRow($row) {
        if (!$row || !$row.length) return;
        var $input = $row.find('.bsn-noleggio-articolo-search');
        var debounceId = $input.data('bsnSearchDebounce');
        if (debounceId) {
            clearTimeout(debounceId);
            $input.removeData('bsnSearchDebounce');
        }
        $row.removeData('bsnArticleResults');
        var $box = $row.find('.bsn-noleggio-articoli-risultati');
        bsnResetSearchDropdownActive($box, '.bsn-risultato-articolo');
        $box.hide().empty();
    }

    function bsnEnsureSingleEmptyArticoloRow() {
        var $rows = $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row');
        var emptyRows = [];

        $rows.each(function() {
            var $r = $(this);
            var idVal = ($r.find('.bsn-noleggio-articolo-id').val() || '').trim();
            var searchVal = ($r.find('.bsn-noleggio-articolo-search').val() || '').trim();
            if (!idVal && !searchVal) {
                emptyRows.push($r);
            }
        });

        if (!emptyRows.length) {
            $('#bsn-noleggio-articolo-add').trigger('click');
            $rows = $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row');
            emptyRows = [$rows.last()];
        }

        if (emptyRows.length > 1) {
            for (var i = 1; i < emptyRows.length; i++) {
                emptyRows[i].remove();
            }
        }
        return emptyRows[0] || null;
            }

            function bsnFocusPrimaRigaArticoloVuota() {
        var $target = bsnEnsureSingleEmptyArticoloRow();
        if ($target && $target.length) {
            bsnCleanupArticoloSearchRow($target);
            setTimeout(function() {
                var $i = $target.find('.bsn-noleggio-articolo-search');
                $i.trigger('focus');
                $i.select();
            }, 35);
        }
    }

    function bsnFindArticoloFromCache(rawValue) {
        var normalized = bsnNormalizeScannerQuery(rawValue).toLowerCase();
        var raw = (rawValue || '').trim().toLowerCase();
        var token = normalized;
        var spazio = normalized.indexOf(' ');
        if (spazio > 0) {
            token = normalized.substring(0, spazio);
        }

        var targets = [raw, normalized, token].filter(function(v, i, arr) {
            return !!v && arr.indexOf(v) === i;
        });

        if (!targets.length) return null;

        return (BSN_ARTICOLI_CACHE || []).find(function(a) {
            var codice = (a && a.codice ? String(a.codice) : '').toLowerCase();
            return targets.indexOf(codice) !== -1;
        }) || null;
    }

    function bsnSelectArticoloInRow($row, art) {
        if (!art || !art.id) return;

        var idArticolo = parseInt(art.id, 10);
        var $input = $row.find('.bsn-noleggio-articolo-search');
        var $hiddenId = $row.find('.bsn-noleggio-articolo-id');
        var $qty = $row.find('.bsn-noleggio-articolo-qty');
        var $box = $row.find('.bsn-noleggio-articoli-risultati');

        bsnCleanupArticoloSearchRow($row);
        $row.removeData('bsnExtra').removeData('bsnOriginalQty').removeData('bsnOriginalPrezzo');

        // CERCA SEMPRE SE ESISTE GIÀ UNA RIGA PER QUESTO ARTICOLO
        var $righe = $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row');
        var $rigaEsistente = null;

        $righe.each(function() {
            var $r = $(this);
            var idRiga = parseInt($r.find('.bsn-noleggio-articolo-id').val(), 10);
            if (idRiga === idArticolo) {
                $rigaEsistente = $r;
                return false;
            }
        });

        if ($rigaEsistente) {
            var $qtyEsistente = $rigaEsistente.find('.bsn-noleggio-articolo-qty');
            var qtyAttuale = parseInt($qtyEsistente.val(), 10) || 0;
            $qtyEsistente.val(qtyAttuale + 1);

            bsnCalcolaTotaleNoleggio();
            bsnCleanupArticoloSearchRow($rigaEsistente);

            if ($rigaEsistente[0] !== $row[0]) {
                $hiddenId.val('');
                $input.val('');
                $qty.val(1);
                $box.hide().empty();
            }

            bsnFocusPrimaRigaArticoloVuota();
            return;
        }

        var label = '';
        if (art.codice) {
            label += '[' + art.codice + '] ';
        }
        label += (art.nome || ('Articolo #' + art.id));

        $hiddenId.val(art.id);
        $input.val(label);
        $qty.val(1);

        var $prezzo = $row.find('.bsn-noleggio-articolo-prezzo');
        var prezzoBase = parseFloat(art.prezzo_giorno) || 0;
        $prezzo.val(prezzoBase.toFixed(2));

        var kit = bsnGetKitByArticoloId(idArticolo);
        if (kit) {
            bsnAttachKitToRow($row, kit);
        }

        $box.hide().empty();
        bsnCalcolaTotaleNoleggio();

        bsnFocusPrimaRigaArticoloVuota();
        }

    $(document).on('input', '.bsn-noleggio-articolo-search', function() {
        var $input = $(this);
        var query = ($input.val() || '').trim();
        var $row = $input.closest('.bsn-noleggio-articolo-row');
        var $box = $row.find('.bsn-noleggio-articoli-risultati');
        var $hiddenId = $row.find('.bsn-noleggio-articolo-id');

        $hiddenId.val('');
        $row.removeData('bsnExtra').removeData('bsnOriginalQty').removeData('bsnOriginalPrezzo');
        var kitInstanceId = $row.data('kit-instance-id');
        if (kitInstanceId) {
            $row.removeData('kit-instance-id');
            bsnRemoveKitInstance(kitInstanceId);
        }

        if (!query || query.length < 2) {
            $row.removeData('bsnArticleResults');
            bsnResetSearchDropdownActive($box, '.bsn-risultato-articolo');
            $box.hide().empty();
            return;
        }

        var debounceId = $input.data('bsnSearchDebounce');
        if (debounceId) clearTimeout(debounceId);

        var queryAtRequest = query;
        $input.data('bsnSearchDebounce', setTimeout(function() {
            bsnSearchArticoliNoleggio(queryAtRequest, function(risultati) {
                var queryNow = ($input.val() || '').trim();
                var hasSelectedId = !!(($hiddenId.val() || '').trim());
                if (queryNow !== queryAtRequest || hasSelectedId) {
                    return;
                }
                $row.data('bsnArticleResults', risultati);
                bsnRenderRisultatiArticoliNoleggio($row, risultati);
            });
        }, 300));
    });

    function bsnNormalizeScannerQuery(value) {
        var raw = (value || '').trim();
        if (!raw) return '';

        var normalized = raw;
        if (normalized[0] === '[') {
            var chiude = normalized.indexOf(']');
            if (chiude > 1) {
                normalized = normalized.substring(1, chiude);
            }
        }

        return (normalized || '').trim();
    }

    function bsnPickBestArticoloMatch(risultati, rawValue) {
        if (!Array.isArray(risultati) || !risultati.length) return null;
        if (risultati.length === 1) return risultati[0];

        var raw = (rawValue || '').trim().toLowerCase();
        var normalized = bsnNormalizeScannerQuery(rawValue).toLowerCase();
        var token = normalized;
        var spazio = normalized.indexOf(' ');
        if (spazio > 0) {
            token = normalized.substring(0, spazio);
        }

        var findByCode = function(target) {
            if (!target) return null;
            return risultati.find(function(a) {
                return (a.codice || '').toLowerCase() === target;
            }) || null;
        };

        return findByCode(raw)
            || findByCode(normalized)
            || findByCode(token)
            || risultati[0];
    }

     // Frecce/Invio/Esc sulla search articolo noleggio
    $(document).on('keydown', '.bsn-noleggio-articolo-search', function(e) {
        var $input = $(this);
        var $row = $input.closest('.bsn-noleggio-articolo-row');
        var $box = $row.find('.bsn-noleggio-articoli-risultati');

        if (bsnHandleSearchDropdownKey(e, {
            box: $box,
            itemSelector: '.bsn-risultato-articolo'
        })) {
            return;
        }

        if (e.key !== 'Enter' && e.keyCode !== 13) {
            return;
        }

        e.preventDefault();

        var debounceId = $input.data('bsnSearchDebounce');
        if (debounceId) {
            clearTimeout(debounceId);
            $input.removeData('bsnSearchDebounce');
        }

        var rawVal = ($input.val() || '').trim();
        if (!rawVal) {
            return;
        }

        var query = bsnNormalizeScannerQuery(rawVal);
        if (!query) {
            return;
        }

        var local = $row.data('bsnArticleResults') || [];
        var bestLocal = bsnPickBestArticoloMatch(local, rawVal);
        if (bestLocal) {
            bsnSelectArticoloInRow($row, bestLocal);
            return;
        }

        var bestCache = bsnFindArticoloFromCache(rawVal);
        if (bestCache) {
            bsnSelectArticoloInRow($row, bestCache);
            return;
        }

        bsnSearchArticoliNoleggio(query, function(risultati) {
            var best = bsnPickBestArticoloMatch(risultati, rawVal);
            if (best) {
                bsnSelectArticoloInRow($row, best);
                 return;
            }
            setTimeout(function() {
                bsnSearchArticoliNoleggio(query, function(secondTry) {
                    var retryBest = bsnPickBestArticoloMatch(secondTry, rawVal) || bsnFindArticoloFromCache(rawVal);
                    if (retryBest) {
                        bsnSelectArticoloInRow($row, retryBest);
                        return;
                    }
                    alert('Articolo non trovato: ' + rawVal);
                });
            }, 140);
        });
    });

     // Seleziona l'articolo al mousedown per evitare che il blur dell'input
     // chiuda il menu prima che la scelta venga registrata.
    $(document).on('mousedown', '.bsn-risultato-articolo', function(e) {
        e.preventDefault();

        var $item = $(this);
        var $row = $item.closest('.bsn-noleggio-articolo-row');
        var art = {
            id: parseInt($item.data('id'), 10),
            codice: $item.data('codice') || '',
            nome: $item.data('nome') || '',
            prezzo_giorno: parseFloat($item.data('prezzo')) || 0
        };

        bsnSelectArticoloInRow($row, art);
    });

    $(document).on('mouseenter', '.bsn-risultato-articolo', function() {
        bsnSyncSearchDropdownHover($(this), '.bsn-risultato-articolo');
    });

// Chiudi lista risultati articoli quando il campo perde il focus
    $(document).on('blur', '.bsn-noleggio-articolo-search', function() {
        var $row = $(this).closest('.bsn-noleggio-articolo-row');
        setTimeout(function() {
            bsnResetSearchDropdownActive($row.find('.bsn-noleggio-articoli-risultati'), '.bsn-risultato-articolo');
            $row.find('.bsn-noleggio-articoli-risultati').hide();
        }, 200);
    });

    // Ricerca clienti nel form noleggio (server-side, indipendente dalla tab Clienti)
    function bsnSearchClientiNoleggio(query, done) {
        $.ajax({
            url: BSN_API.root + 'clienti/search',
            method: 'GET',
            data: { search: query, limit: 15 },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var items = (response && response.clienti) ? response.clienti : [];
                 if (Array.isArray(items) && items.length) {
                    bsnMergeClientiCache(items, false);
                }
                done(items);
            },
            error: function() {
                done([]);
            }
        });
    }

    function bsnSelectClienteNoleggio(cliente) {
        if (!cliente || !cliente.id) {
            return;
        }

        var label = cliente.nome || ('Cliente #' + cliente.id);
        if (cliente.cf_piva) {
            label += ' (' + cliente.cf_piva + ')';
        }

        $('#bsn-noleggio-cliente-id').val(cliente.id);
        $('#bsn-noleggio-cliente-search').val(label);
        bsnResetSearchDropdownActive($('#bsn-noleggio-clienti-risultati'), '.bsn-risultato-cliente');
        $('#bsn-noleggio-clienti-risultati').hide().empty();

        var exists = (BSN_CLIENTI_CACHE || []).some(function(c) {
            return parseInt(c.id, 10) === parseInt(cliente.id, 10);
        });
        if (!exists) {
            BSN_CLIENTI_CACHE.push(cliente);
        }

        bsnPreviewClienteLastTriedId = null;
        bsnPreviewClienteFetchInFlight = false;
        bsnFetchClienteByIdForPreview(parseInt(cliente.id, 10), function(found) {
            if (found && found.id) {
                var replaced = false;
                BSN_CLIENTI_CACHE = (BSN_CLIENTI_CACHE || []).map(function(c) {
                    if (parseInt(c.id, 10) === parseInt(found.id, 10)) {
                        replaced = true;
                        return found;
                    }
                    return c;
                });
                if (!replaced) {
                    BSN_CLIENTI_CACHE.push(found);
                }

                var fullLabel = found.nome || ('Cliente #' + found.id);
                if (found.cf_piva) {
                    fullLabel += ' (' + found.cf_piva + ')';
                }
                $('#bsn-noleggio-cliente-search').val(fullLabel);
            }

            bsnCalcolaTotaleNoleggio();
        });
    }

    $('#bsn-noleggio-cliente-search').on('input', function() {
        var $input = $(this);
        var query = ($input.val() || '').trim();
        var $box = $('#bsn-noleggio-clienti-risultati');
        var $hiddenId = $('#bsn-noleggio-cliente-id');

        $hiddenId.val('');

        if (!query || query.length < 2) {
            bsnResetSearchDropdownActive($box, '.bsn-risultato-cliente');
            $box.hide().empty();
            $box.removeData('bsnClientResults');
            return;
        }

         var debounceId = $input.data('bsnClienteSearchDebounce');
        if (debounceId) clearTimeout(debounceId);

        var queryAtRequest = query;
        $input.data('bsnClienteSearchDebounce', setTimeout(function() {
            bsnSearchClientiNoleggio(queryAtRequest, function(risultati) {
                var queryNow = ($input.val() || '').trim();
                var hasSelectedId = !!(($hiddenId.val() || '').trim());
                if (queryNow !== queryAtRequest || hasSelectedId) {
                    return;
                }
                $box.empty();
                $box.data('bsnClientResults', risultati);

        if (!risultati.length) {
                    bsnResetSearchDropdownActive($box, '.bsn-risultato-cliente');
                    $box.append('<div style="padding:5px;">Nessun cliente trovato.</div>');
                    $box.show();
                    return;
                }

        risultati.slice(0, 15).forEach(function(c) {
                    var label = (c.nome || ('Cliente #' + c.id));
                    if (c.cf_piva) {
                        label += ' (' + c.cf_piva + ')';
                    }
                    var html = '' +
                        '<div class="bsn-risultato-cliente" data-id="' + c.id + '" data-nome="' + (c.nome || '') + '" data-cf="' + (c.cf_piva || '') + '" style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">' +
                            label +
                        '</div>';
                    $box.append(html);
                });

        $box.show();
                bsnSetSearchDropdownActive($box, '.bsn-risultato-cliente', 0);
            });
        }, 300));
    });

    // Click su un risultato della ricerca cliente
    $(document).on('mousedown', '.bsn-risultato-cliente', function(e) {
        e.preventDefault();

        var id = parseInt($(this).data('id'), 10);
        var nome = $(this).data('nome') || '';
        var cf = $(this).data('cf') || '';
        var cliente = (BSN_CLIENTI_CACHE || []).find(function(c) {
            return parseInt(c.id, 10) === id;
        });

        if (!cliente) {
            cliente = {
                id: id,
                nome: nome,
                cf_piva: cf
            };
        }

        bsnSelectClienteNoleggio(cliente);
    });

    $(document).on('mouseenter', '.bsn-risultato-cliente', function() {
        bsnSyncSearchDropdownHover($(this), '.bsn-risultato-cliente');
    });

    $(document).on('keydown', '#bsn-noleggio-cliente-search', function(e) {
        if (bsnHandleSearchDropdownKey(e, {
            box: $('#bsn-noleggio-clienti-risultati'),
            itemSelector: '.bsn-risultato-cliente'
        })) {
            return;
        }
    });

    // Chiudi la lista clienti quando il campo perde il focus (con un piccolo delay per permettere il click)
    $('#bsn-noleggio-cliente-search').on('blur', function() {
        setTimeout(function() {
            bsnResetSearchDropdownActive($('#bsn-noleggio-clienti-risultati'), '.bsn-risultato-cliente');
            $('#bsn-noleggio-clienti-risultati').hide();
        }, 200);
    });

    // ===== INCARICATO AL RITIRO =====

    // Checkbox: mostra/nascondi il campo incaricato inline
    $(document).on('change', '#bsn-noleggio-ha-incaricato', function() {
        if ($(this).is(':checked')) {
            $('#bsn-noleggio-incaricato-wrapper').show().find('#bsn-noleggio-incaricato-search').focus();
        } else {
            $('#bsn-noleggio-incaricato-wrapper').hide();
            $('#bsn-noleggio-incaricato-search').val('');
            $('#bsn-noleggio-incaricato-id').val('');
            $('#bsn-noleggio-incaricato-risultati').hide().empty();
            $('#bsn-preview-incaricato').text('');
            $('#bsn-preview-incaricato-row').hide();
        }
    });

    // Ricerca incaricato (riutilizza lo stesso endpoint clienti)
    $('#bsn-noleggio-incaricato-search').on('input', function() {
        var $input = $(this);
        var query = ($input.val() || '').trim();
        var $box = $('#bsn-noleggio-incaricato-risultati');
        var $hiddenId = $('#bsn-noleggio-incaricato-id');

        $hiddenId.val('');

        if (!query || query.length < 2) {
            bsnResetSearchDropdownActive($box, '.bsn-risultato-incaricato');
            $box.hide().empty();
            return;
        }

        var debounceId = $input.data('bsnIncaricatoSearchDebounce');
        if (debounceId) clearTimeout(debounceId);

        var queryAtRequest = query;
        $input.data('bsnIncaricatoSearchDebounce', setTimeout(function() {
            bsnSearchClientiNoleggio(queryAtRequest, function(risultati) {
                var queryNow = ($input.val() || '').trim();
                var hasSelectedId = !!(($hiddenId.val() || '').trim());
                if (queryNow !== queryAtRequest || hasSelectedId) return;

                $box.empty();
                if (!risultati.length) {
                    bsnResetSearchDropdownActive($box, '.bsn-risultato-incaricato');
                    $box.append('<div style="padding:5px;">Nessun cliente trovato.</div>');
                    $box.show();
                    return;
                }
                risultati.slice(0, 15).forEach(function(c) {
                    var label = (c.nome || ('Cliente #' + c.id));
                    if (c.cf_piva) label += ' (' + c.cf_piva + ')';
                    $box.append(
                        '<div class="bsn-risultato-incaricato" data-id="' + c.id + '" data-nome="' + (c.nome || '') + '" data-cf="' + (c.cf_piva || '') + '" style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">' +
                        label + '</div>'
                    );
                });
                $box.show();
                bsnSetSearchDropdownActive($box, '.bsn-risultato-incaricato', 0);
            });
        }, 300));
    });

    // Click su un risultato incaricato
    $(document).on('mousedown', '.bsn-risultato-incaricato', function(e) {
        e.preventDefault();
        var id = parseInt($(this).data('id'), 10);
        var nome = $(this).data('nome') || '';
        var cf = $(this).data('cf') || '';
        var label = nome + (cf ? ' (' + cf + ')' : '');
        $('#bsn-noleggio-incaricato-id').val(id);
        $('#bsn-noleggio-incaricato-search').val(label);
        $('#bsn-preview-incaricato').text(label);
        $('#bsn-preview-incaricato-row').show();
        bsnResetSearchDropdownActive($('#bsn-noleggio-incaricato-risultati'), '.bsn-risultato-incaricato');
        $('#bsn-noleggio-incaricato-risultati').hide().empty();
    });

    $(document).on('mouseenter', '.bsn-risultato-incaricato', function() {
        bsnSyncSearchDropdownHover($(this), '.bsn-risultato-incaricato');
    });

    $(document).on('keydown', '#bsn-noleggio-incaricato-search', function(e) {
        bsnHandleSearchDropdownKey(e, {
            box: $('#bsn-noleggio-incaricato-risultati'),
            itemSelector: '.bsn-risultato-incaricato'
        });
    });

    $('#bsn-noleggio-incaricato-search').on('blur', function() {
        setTimeout(function() {
            bsnResetSearchDropdownActive($('#bsn-noleggio-incaricato-risultati'), '.bsn-risultato-incaricato');
            $('#bsn-noleggio-incaricato-risultati').hide();
        }, 200);
    });

    // Trigger calcolo quando cambiano le date
    $(document).on('change', '#bsn-form-noleggio input[name="data_inizio"], #bsn-form-noleggio input[name="data_fine"]', function() {
        bsnSyncLogisticDatesFromUsage();
        bsnCalcolaTotaleNoleggio();
    });

    $(document).on('change', '#bsn-form-noleggio input[name="data_ritiro"], #bsn-form-noleggio input[name="data_riconsegna"]', function() {
        bsnCalcolaTotaleNoleggio();
    });

    // Trigger calcolo quando cambia qty
    $(document).on('change input', '.bsn-noleggio-articolo-qty', function() {
        bsnCalcolaTotaleNoleggio();
    });

    // Trigger calcolo quando cambia prezzo riga
    $(document).on('change input', '.bsn-noleggio-articolo-prezzo', function() {
        bsnCalcolaTotaleNoleggio();
    });

    // Trigger calcolo quando cambia sconto globale
    $(document).on('change input', '#bsn-noleggio-sconto-globale', function() {
        bsnCalcolaTotaleNoleggio();
    });

    // Blocca ENTER dentro il wrapper articoli noleggio
    $(document).on('keydown', '#bsn-noleggio-articoli-wrapper input', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            // Qui metteremo la logica avanzata per la pistola
            return false;
        }
    });

    $(document).on('click', '.bsn-save-preventivo, .bsn-save-noleggio', function() {
        var stato = $(this).data('stato') || 'bozza';
        $('#bsn-form-noleggio input[name="stato"]').val(stato);
        $('#bsn-form-noleggio').trigger('submit');
    });

    // Submit form noleggio (crea o modifica, con articoli e warning sovrapposizioni)
    $('#bsn-form-noleggio').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        // ID noleggio: se valorizzato, siamo in modalità "modifica"
        var noleggioId = $('#bsn-noleggio-id').val();

        // Articoli: costruisci array [{id, qty, prezzo}, ...]
        var articoli = [];
        $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row').each(function() {
            var line = bsnBuildNoleggioLineFromRow($(this), true);
            if (line.id && line.qty > 0) {
                delete line.rowModified;
                articoli.push(line);
            }
        });

        // almeno un articolo obbligatorio
        if (articoli.length === 0) {
            alert('Devi aggiungere almeno un articolo al noleggio.');
            return;
        }

        var dati = {
            noleggio_id:       noleggioId,
            cliente_id:        $form.find('input[name="cliente_id"]').val(),
            data_inizio:       $form.find('input[name="data_inizio"]').val(),
            data_fine:         $form.find('input[name="data_fine"]').val(),
            data_ritiro:       $form.find('input[name="data_ritiro"]').val(),
            data_riconsegna:   $form.find('input[name="data_riconsegna"]').val(),
            stato:             $form.find('input[name="stato"]').val(),
            note:              $form.find('textarea[name="note"]').val(),
            articoli:          JSON.stringify(articoli),
            consenti_overbook: $form.find('#bsn-noleggio-consenti-overbook').is(':checked') ? 1 : 0,
            // nuovi campi logistica/trasporto
            luogo_destinazione: $form.find('input[name="luogo_destinazione"]').val(),
            trasporto_mezzo:    $form.find('input[name="trasporto_mezzo"]').val(),
            cauzione:           $form.find('input[name="cauzione"]').val(),
            causale_trasporto:  $form.find('input[name="causale_trasporto"]').val(),
            metodo_pagamento:   $form.find('input[name="metodo_pagamento"]').val(),
            sconto_globale: $form.find('#bsn-noleggio-sconto-globale').val(),
            kits: JSON.stringify(bsnSerializeKitsForSave()),
            incaricato_ritiro_cliente_id: $form.find('input[name="incaricato_ritiro_cliente_id"]').val() || ''
        };


        if (!dati.cliente_id || !dati.data_inizio || !dati.data_fine) {
            alert('Cliente, data inizio noleggio e data termine noleggio sono obbligatori.');
            return;
        }

        var dataInizioUtilizzo = new Date(dati.data_inizio + 'T00:00:00').getTime();
        var dataFineUtilizzo = new Date(dati.data_fine + 'T00:00:00').getTime();
        var dataRitiroEffettiva = dati.data_ritiro || dati.data_inizio;
        var dataRiconsegnaEffettiva = dati.data_riconsegna || dati.data_fine;
        var dataRitiroTs = new Date(dataRitiroEffettiva + 'T00:00:00').getTime();
        var dataRiconsegnaTs = new Date(dataRiconsegnaEffettiva + 'T00:00:00').getTime();

        if (dataInizioUtilizzo > dataFineUtilizzo) {
            alert('La Data inizio noleggio non può essere successiva alla Data termine noleggio.');
            return;
        }

        if (dataRitiroTs > dataRiconsegnaTs) {
            alert('La Data ritiro non può essere successiva alla Data riconsegna.');
            return;
        }

        // Decidi endpoint: crea vs aggiorna
        var urlEndpoint;
        var azioneTesto;
        if (noleggioId) {
            urlEndpoint = BSN_API.root + 'noleggi/update';
            azioneTesto = 'aggiornato';
        } else {
            urlEndpoint = BSN_API.root + 'noleggi';
            azioneTesto = 'salvato';
        }

        $.ajax({
            url: urlEndpoint,
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: dati,
            success: function(response) {
                // Reset warning box
                var $warnBox  = $('#bsn-noleggi-warning');
                var $warnList = $('#bsn-noleggi-warning-list');
                $warnList.empty();
                $warnBox.hide();

                // Mostra warning sovrapposizioni se presenti (non bloccanti)
                if (response && response.sovrapposizioni && response.sovrapposizioni.length) {
                    response.sovrapposizioni.forEach(function(msg) {
                        $warnList.append('<li>' + msg + '</li>');
                    });
                    $warnBox.show();
                    alert('Noleggio ' + azioneTesto + ', ma ci sono sovrapposizioni. Controlla il riquadro di attenzione sotto al form.');
                } else {
                    alert('Noleggio ' + azioneTesto + ' correttamente.');
                }

                // reset completa: esce dalla modalità "modifica"
                $form[0].reset();
                $('#bsn-form-noleggio input[type="date"]').trigger('input');
                $('#bsn-noleggio-id').val('');
                $('#bsn-form-noleggio input[name="stato"]').val('bozza');
                $('#bsn-form-noleggio')
                    .removeData('bsnLastDataInizio')
                    .removeData('bsnLastDataFine')
                    .removeData('bsnOriginalDataInizio')
                    .removeData('bsnOriginalDataFine');
                $('#bsn-noleggio-preview').hide();
                $('#bsn-noleggi-warning').hide();
                $('#bsn-noleggi-warning-list').empty();
                $('#bsn-preview-articoli-tbody').empty();
                $('#bsn-preview-dettagli-articoli').empty();
                $('#bsn-preview-kit-componenti').empty();
                $('#bsn-noleggio-clienti-risultati').hide().empty();
                $('#bsn-noleggio-articoli-wrapper').empty();
                bsnResetNoleggioKits();
                $('#bsn-noleggio-articolo-add').trigger('click');
                bsnCaricaNoleggi();
            },
            error: function(err) {
                console.error(err);

                // Gestione errore blocco sovrapposizioni
                if (err.responseJSON && err.responseJSON.code === 'bsn_sovrapposizioni_blocco') {
                    var messaggioErrore = err.responseJSON.message || 'Sovrapposizioni rilevate';

                    // Pulisci il messaggio per estrarre solo le righe degli articoli
                    var righe = messaggioErrore.split('\n').filter(function(riga) {
                        return riga.trim().startsWith('-');
                    });

                    var $warnBox  = $('#bsn-noleggi-warning');
                    var $warnList = $('#bsn-noleggi-warning-list');
                    $warnList.empty();

                    righe.forEach(function(riga) {
                        // Rimuovi il "- " iniziale
                        var msg = riga.trim().substring(2);
                        $warnList.append('<li>' + msg + '</li>');
                    });

                    $warnBox.show();

                    alert('ATTENZIONE: Sovrapposizioni rilevate!\n\nPer salvare comunque questo noleggio, spunta la casella "Consenti comunque il superamento della disponibilità (overbooking)" e riprova.');
                } else {
                    // Errore generico
                    var msgErrore = 'Errore nel salvataggio noleggio.';
                    if (err.responseJSON && err.responseJSON.message) {
                        msgErrore += '\n\n' + err.responseJSON.message;
                    }
                    alert(msgErrore);
                }
            }
        });

    });
    function bsnCaricaNoleggi() {
        $.ajax({
            url: BSN_API.root + 'noleggi',
            method: 'GET',
            data: {
                search: bsnListState.noleggi.search,
                stato: bsnListState.noleggi.stato,
                limit: bsnListState.noleggi.limit
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var wrapper = $('#bsn-lista-noleggi');
                wrapper.empty();

                if (!response.noleggi || !response.noleggi.length) {
                    wrapper.html('<p>Nessun noleggio salvato.</p>');
                    if (typeof bsnUpdateCalendarData === 'function') {
                        bsnUpdateCalendarData([]);
                    }
                    return;
                }

                if (typeof bsnUpdateCalendarData === 'function') {
                    bsnUpdateCalendarData(response.noleggi || []);
                }

                var html = '<table class="bsn-tabella" id="bsn-noleggi-table"><thead><tr>' +
                    '<th>ID</th><th>Cliente</th><th>Periodo di utilizzo</th><th>Periodo di ritiro/riconsegna</th><th>Stato</th><th>Articoli</th><th>Azioni</th>' +
                    '</tr></thead><tbody>';

                response.noleggi.forEach(function(n) {
                    var stato       = (n.stato || '').toLowerCase();
                    var classeStato = '';
                    var statoLabel  = (n.stato || '');
                    if (stato === 'bozza') classeStato = 'bsn-stato-bozza';
                    else if (stato === 'preventivo') classeStato = 'bsn-stato-preventivo';
                    else if (stato === 'attivo') classeStato = 'bsn-stato-attivo';
                    else if (stato === 'chiuso') classeStato = 'bsn-stato-chiuso';
                    else if (stato === 'ritardo') classeStato = 'bsn-stato-ritardo';
                    var origineLabel = bsnFormatOrigineLabel(n.origine);
                    if (origineLabel.highlighted && origineLabel.icon) {
                        statoLabel = origineLabel.icon + ' ' + statoLabel;
                    }

                    var btnDettagli = '<button type="button" class="btn bsn-noleggio-details" title="Dettagli">🔍 Dettagli</button>';
                    var btnDuplica = '<button type="button" class="btn bsn-noleggio-duplica" title="Duplica noleggio">📑 Duplica</button>';
                    var btnModifica = '<button type="button" class="btn bsn-noleggio-edit" title="Modifica noleggio">✏️ Modifica</button>';
                    var btnIspeziona = '<a href="' + window.location.origin + '/ispeziona/?id=' + encodeURIComponent(n.id) + '" class="btn bsn-noleggio-ispeziona" title="Ispeziona noleggio">👁️ Ispeziona</a>';
                    var btnElimina = '<button type="button" class="btn bsn-noleggio-delete" title="Elimina noleggio" style="color:#b00;">🗑️ Elimina</button>';
                    var btnInviaPreventivo = '<button type="button" class="btn bsn-noleggio-invia-preventivo" title="Invia preventivo">✉️ Proposta</button>';

                    html += '<tr class="bsn-noleggio-row ' + classeStato + '" data-id="' + n.id + '" data-stato="' + stato + '">' +
                        '<td>' + n.id + '</td><td>' + (n.cliente_nome || '') + '</td><td>' + (n.data_da || '') + ' / ' + (n.data_a || '') + '</td><td>' + (n.data_ritiro_effettiva || '') + ' / ' + (n.data_riconsegna_effettiva || '') + '</td>' +
                        '<td>' + statoLabel + (n.ticket_alert ? ' <span class="bsn-ticket-badge">Ticket aperto</span>' : '') + '</td>' +
                        '<td>' + (n.articoli_riassunto || '-') + '</td><td><div class="bsn-azioni-grid">' +
                        (stato === 'preventivo'
                            ? (btnDettagli + btnModifica + btnDuplica + btnIspeziona + btnElimina + btnInviaPreventivo)
                            : (btnDettagli + btnDuplica +
                                ((stato === 'attivo' || stato === 'ritardo') ? '<button type="button" class="button button-secondary bsn-noleggio-rientro" title="Chiudi / Rientro noleggio">🚚 Rientro</button>' : (stato === 'chiuso' ? '<button type="button" class="btn bsn-noleggio-ticket-shortcut" data-noleggio-id="' + n.id + '" title="Apri ticket">🎫 Ticket</button>' : '')) +
                                (stato === 'bozza' ? btnModifica : '<a href="' + (BSN_API.root + 'noleggi/pdf?id=' + encodeURIComponent(n.id) + '&_wpnonce=' + encodeURIComponent(BSN_API.nonce)) + '" class="btn bsn-noleggio-pdf" target="_blank" rel="noopener noreferrer" title="PDF noleggio">📄 PDF</a>') +
                                (stato === 'bozza' ? '<a href="' + window.location.origin + '/finalizza/?id=' + encodeURIComponent(n.id) + '" class="button button-primary" title="Finalizza noleggio">✅ Finalizza</a>' : '') +
                                btnIspeziona + btnElimina)
                        ) + '</div></td></tr>' +
                        '<tr class="bsn-noleggio-row-details" data-id="' + n.id + '" style="display:none;"><td colspan="7"><div class="bsn-noleggio-details-container"><em>Caricamento dettagli...</em></div></td></tr>';
                });

                html += '</tbody></table>';
                wrapper.html(html);
            },
            error: function(err) {
                console.error(err);
                $('#bsn-lista-noleggi').html('<p>Errore nel caricare i noleggi.</p>');
            }
        });
    }

    // Click su "Dettagli" noleggio (mostra note + articoli con ubicazione e correlati)
    $(document).on('click', '.bsn-noleggio-details', function() {
        var $btn   = $(this);
        var $row   = $btn.closest('.bsn-noleggio-row');
        var id     = $row.data('id');
        var $dRow  = $('.bsn-noleggio-row-details[data-id="' + id + '"]');
        var $box   = $dRow.find('.bsn-noleggio-details-container');

        // Toggle base: se già visibile, nascondi
        if ($dRow.is(':visible')) {
            $dRow.hide();
            return;
        }

        // Mostra riga dettagli e metti un messaggio di caricamento
        $dRow.show();
        $box.html('<em>Caricamento dettagli...</em>');

        $.ajax({
            url: BSN_API.root + 'noleggi/dettaglio',
            method: 'GET',
            data: { id: id },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var html = '';

                html += '<div style="margin-bottom:8px;"><strong>Periodo di utilizzo:</strong> ' + bsnConvertiDataPerPrintStr(response.data_inizio || '') + ' / ' + bsnConvertiDataPerPrintStr(response.data_fine || '') + '</div>';
                html += '<div style="margin-bottom:8px;"><strong>Periodo di ritiro/riconsegna:</strong> ' + bsnConvertiDataPerPrintStr(response.data_ritiro_effettiva || response.data_inizio || '') + ' / ' + bsnConvertiDataPerPrintStr(response.data_riconsegna_effettiva || response.data_fine || '') + '</div>';

                var dettaglioOrigine = bsnFormatOrigineLabel(response.origine);
                html += '<div style="margin-bottom:8px;"><strong>Origine richiesta:</strong> ' + (dettaglioOrigine.icon ? dettaglioOrigine.icon + ' ' : '') + dettaglioOrigine.label + '</div>';
                html += '<div style="margin-bottom:8px;"><strong>Note richiesta:</strong> ' + (response.note_operatore ? ('<span>' + response.note_operatore + '</span>') : '<span>-</span>') + '</div>';
                html += '<div style="margin-bottom:8px;"><strong>Note cliente:</strong> ' + (response.note_cliente ? ('<span>' + response.note_cliente + '</span>') : '<span>-</span>') + '</div>';
                if (response.service_summary_html) {
                    html += response.service_summary_html;
                }

                // Dettagli logistica/trasporto
                var hasLogistica = response.luogo_destinazione || response.trasporto_mezzo || response.cauzione || response.metodo_pagamento || response.causale_trasporto;
                if (hasLogistica) {
                    html += '<div style="margin-bottom:8px; padding:6px 8px; background:#f9f9f9; border-left:3px solid #0073aa;">';
                    html += '<div><strong>Luogo destinazione:</strong> ' + (response.luogo_destinazione || '-') + '</div>';
                    html += '<div><strong>Trasporto a mezzo:</strong> ' + (response.trasporto_mezzo || '-') + '</div>';
                    html += '<div><strong>Cauzione:</strong> ' + (response.cauzione || '-') + '</div>';
                    html += '<div><strong>Metodo pagamento:</strong> ' + (response.metodo_pagamento || '-') + '</div>';
                    html += '<div><strong>Causale di trasporto:</strong> ' + (response.causale_trasporto || '-') + '</div>';
                    html += '</div>';
                }

                // Incaricato al ritiro
                var incDet = response.incaricato_ritiro;
                if (incDet && incDet.id) {
                    html += '<div style="margin-bottom:8px; padding:6px 8px; background:#fff8f8; border-left:3px solid #c00;">';
                    html += '<div><strong style="color:#c00;">Incaricato al ritiro:</strong> ' + (incDet.nome || '-') + '</div>';
                    if (incDet.cf_piva)  html += '<div>CF/P.IVA: ' + incDet.cf_piva + '</div>';
                    if (incDet.telefono) html += '<div>Tel: ' + incDet.telefono + '</div>';
                    if (incDet.email)    html += '<div>Email: ' + incDet.email + '</div>';
                    html += '</div>';
                }

                // Articoli
                var articoliDettaglio = (response.articoli_materiali && response.articoli_materiali.length)
                    ? response.articoli_materiali
                    : (response.articoli || []);
                if (articoliDettaglio && articoliDettaglio.length) {
                    html += '<div style="margin-top:5px;">';
                    html += '<strong>Articoli noleggiati:</strong>';
                    html += '<ul style="margin:5px 0 0 15px; padding:0;">';

                    articoliDettaglio.forEach(function(a) {
                        var label = '';

                        // [CODICE] Nome
                        if (a.codice) {
                            label += '[' + a.codice + '] ';
                        }
                        label += (a.nome || ('Articolo #' + a.id));

                        // Qty
                        label += ' – qty: ' + a.qty;

                        // Ubicazione
                        var ubic = a.ubicazione || '-';
                        label += ' – ubicazione: ' + ubic;

                        // Correlati (moltiplicati per qty articolo)
                        var corrTesto = '-';
                        if (a.correlati && a.correlati.length) {
                            var pezzi = a.correlati.map(function(c) {
                                var qtyCorrelato = (c.qty || 1) * a.qty; // MOLTIPLICA per qty articolo
                                var n = c.nome || '';
                                return qtyCorrelato + 'x ' + n;
                            });
                            corrTesto = pezzi.join(', ');
                        }
                        label += ' – correlati: ' + corrTesto;

                        html += '<li>' + label + '</li>';
                    });

                    html += '</ul>';
                    html += '</div>';
                } else {
                    html += '<div><em>Nessun articolo associato a questo noleggio.</em></div>';
                }

                if (response.service_lines && response.service_lines.length) {
                    html += '<div style="margin-top:8px;">';
                    html += '<strong>Righe economiche servizio:</strong>';
                    html += '<ul style="margin:5px 0 0 15px; padding:0;">';

                    response.service_lines.forEach(function(line) {
                        var label = line.service_component_label || line.nome || ('Servizio #' + line.id);
                        if (line.codice) {
                            label = '[' + line.codice + '] ' + label;
                        }
                        label += ' - qty: ' + (line.qty || 1);
                        if (line.prezzo !== null && line.prezzo !== undefined && line.prezzo !== '') {
                            label += ' - prezzo: ' + bsnFormatEuroAmount(line.prezzo);
                        }
                        html += '<li>' + label + '</li>';
                    });

                    html += '</ul>';
                    html += '</div>';
                }

                if (response.kits && response.kits.length) {
                    html += '<div style="margin-top:8px;">';
                    html += '<strong>Kit presenti:</strong>';
                    response.kits.forEach(function(kit) {
                        html += '<div style="margin-top:4px;"><em>' + (kit.kit_label || kit.nome_kit || 'Kit') + '</em></div>';
                        if (kit.components && kit.components.length) {
                            html += '<ul style="margin:4px 0 0 15px; padding:0;">';
                            kit.components.forEach(function(comp) {
                                var label = (comp.ruolo || '-') + ' – qty: ' + (comp.qty || 1);
                                if (comp.articolo_codice) {
                                    label += ' – [' + comp.articolo_codice + ']';
                                }
                                label += ' ' + (comp.articolo_nome || ('Articolo #' + comp.articolo_id));
                                html += '<li>' + label + '</li>';
                            });
                            html += '</ul>';
                        }
                    });
                    html += '</div>';
                }

                $box.html(html);
            },
            error: function(err) {
                console.error(err);
                $box.html('<em>Errore nel caricare i dettagli del noleggio.</em>');
            }
        });
    });

    // Click su "Modifica" nella tabella noleggi
    $(document).on('click', '.bsn-noleggio-edit', function() {
        var $row = $(this).closest('.bsn-noleggio-row');
        var id   = $row.data('id');

        if (!id) return;

        var clienteNome = $row.find('td').eq(1).text();
        var stato       = $row.data('stato') || $row.find('td').eq(4).text();

        // Imposta ID noleggio in hidden e modalità "edit"
        $('#bsn-noleggio-id').val(id);

        // Compila i campi base del form
        $('#bsn-noleggio-cliente-search').val(clienteNome);
        $('input[name="stato"]').val(stato.toLowerCase());

        // Prima svuotiamo il wrapper articoli
        var $wrapper = $('#bsn-noleggio-articoli-wrapper');
        $wrapper.empty();
        bsnResetNoleggioKits();

        // Chiamata API dettaglio per avere articoli, note e cliente_id corretto
        $.ajax({
            url: BSN_API.root + 'noleggi/dettaglio',
            method: 'GET',
            data: { id: id },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                // cliente_id reale
                if (response.cliente_id) {
                    $('#bsn-noleggio-cliente-id').val(response.cliente_id);
                }

                $('input[name="data_inizio"]').val(response.data_inizio || '');
                $('input[name="data_fine"]').val(response.data_fine || '');
                $('input[name="data_ritiro"]').val(response.data_ritiro || response.data_inizio || '');
                $('input[name="data_riconsegna"]').val(response.data_riconsegna || response.data_fine || '');
                $('#bsn-form-noleggio input[type="date"]').trigger('input');
                $('#bsn-form-noleggio').data('bsnLastDataInizio', response.data_inizio || '');
                $('#bsn-form-noleggio').data('bsnLastDataFine', response.data_fine || '');
                $('#bsn-form-noleggio').data('bsnOriginalDataInizio', response.data_inizio || '');
                $('#bsn-form-noleggio').data('bsnOriginalDataFine', response.data_fine || '');

                // luogo, trasporto, cauzione, metodo, causale
                if (typeof response.luogo_destinazione !== 'undefined') {
                    $('input[name="luogo_destinazione"]').val(response.luogo_destinazione || '');
                }
                if (typeof response.trasporto_mezzo !== 'undefined') {
                    $('input[name="trasporto_mezzo"]').val(response.trasporto_mezzo || '');
                }
                if (typeof response.cauzione !== 'undefined') {
                    $('input[name="cauzione"]').val(response.cauzione || '');
                }
                if (typeof response.metodo_pagamento !== 'undefined') {
                    $('input[name="metodo_pagamento"]').val(response.metodo_pagamento || '');
                }
                if (typeof response.causale_trasporto !== 'undefined') {
                    $('input[name="causale_trasporto"]').val(response.causale_trasporto || '');
                }

                // note
                if (typeof response.note !== 'undefined') {
                    $('textarea[name="note"]').val(response.note);
                }

                // sconto globale
                if (typeof response.sconto_globale !== 'undefined' && response.sconto_globale !== null) {
                    $('#bsn-noleggio-sconto-globale').val(response.sconto_globale);
                } else {
                    $('#bsn-noleggio-sconto-globale').val('0');
                }

                // incaricato al ritiro
                var inc = response.incaricato_ritiro;
                if (inc && inc.id) {
                    var incLabel = inc.nome + (inc.cf_piva ? ' (' + inc.cf_piva + ')' : '');
                    $('#bsn-noleggio-ha-incaricato').prop('checked', true);
                    $('#bsn-noleggio-incaricato-wrapper').show();
                    $('#bsn-noleggio-incaricato-id').val(inc.id);
                    $('#bsn-noleggio-incaricato-search').val(incLabel);
                    $('#bsn-preview-incaricato').text(incLabel);
                    $('#bsn-preview-incaricato-row').show();
                } else {
                    $('#bsn-noleggio-ha-incaricato').prop('checked', false);
                    $('#bsn-noleggio-incaricato-wrapper').hide();
                    $('#bsn-noleggio-incaricato-id').val('');
                    $('#bsn-noleggio-incaricato-search').val('');
                    $('#bsn-preview-incaricato').text('');
                    $('#bsn-preview-incaricato-row').hide();
                }

                // ricostruisci righe articoli
                if (response.articoli && response.articoli.length) {
                    response.articoli.forEach(function(a) {
                        var $rowArt = $(
                            '<div class="bsn-noleggio-articolo-row">' +
                                '<input type="text" class="bsn-noleggio-articolo-search" ' +
                                    'placeholder="Cerca articolo per nome o codice..." autocomplete="off" ' +
                                    'style="flex:1 1 200px; min-width:150px;">' +
                                '<input type="hidden" class="bsn-noleggio-articolo-id" name="articoli_id[]">' +
                                '<input type="number" class="bsn-noleggio-articolo-prezzo" ' +
                                    'name="articoli_prezzo[]" min="0" step="0.01" placeholder="Prezzo" ' +
                                    'style="max-width:90px;">' +
                                '<input type="number" class="bsn-noleggio-articolo-qty" ' +
                                    'name="articoli_qty[]" min="1" value="1" style="max-width:60px;">' +
                                '<button type="button" class="btn bsn-noleggio-articolo-remove">X</button>' +
                                '<div class="bsn-noleggio-articoli-risultati" ' +
                                    'style="border:1px solid #ccc; max-height:150px; overflow-y:auto; ' +
                                           'display:none; background:#fff; position:relative; z-index:10;"></div>' +
                            '</div>'
                        );

                        $rowArt.find('.bsn-noleggio-articolo-id').val(a.id);
                        $rowArt.find('.bsn-noleggio-articolo-qty').val(a.qty);

                        // prezzo custom (se presente nel JSON del noleggio)
                        if (typeof a.prezzo !== 'undefined' && a.prezzo !== null && a.prezzo !== '') {
                            $rowArt.find('.bsn-noleggio-articolo-prezzo')
                               .val(parseFloat(a.prezzo).toFixed(2));
                        }

                        $rowArt.data('bsnExtra', bsnPickNoleggioExtraFields(a));
                        $rowArt.data('bsnOriginalQty', parseInt(a.qty, 10) || 1);
                        $rowArt.data('bsnOriginalPrezzo', bsnNormalizePriceForCompare(a.prezzo));

                        var label = '';
                        if (a.codice) {
                            label += '[' + a.codice + '] ';
                        }
                        label += a.nome || ('Articolo #' + a.id);
                        $rowArt.find('.bsn-noleggio-articolo-search').val(label);

                        $wrapper.append($rowArt);
                    });
                } else {
                    // riga vuota fallback, con anche il campo prezzo
                    var $rowVuota = $(
                        '<div class="bsn-noleggio-articolo-row">' +
                            '<input type="text" class="bsn-noleggio-articolo-search" ' +
                                'placeholder="Cerca articolo per nome o codice..." autocomplete="off" ' +
                                'style="flex:1 1 200px; min-width:150px;">' +
                            '<input type="hidden" class="bsn-noleggio-articolo-id" name="articoli_id[]">' +
                            '<input type="number" class="bsn-noleggio-articolo-prezzo" ' +
                                'name="articoli_prezzo[]" min="0" step="0.01" placeholder="Prezzo" ' +
                                'style="max-width:90px;">' +
                            '<input type="number" class="bsn-noleggio-articolo-qty" ' +
                                'name="articoli_qty[]" min="1" value="1" style="max-width:60px;">' +
                            '<button type="button" class="btn bsn-noleggio-articolo-remove">X</button>' +
                            '<div class="bsn-noleggio-articoli-risultati" ' +
                                'style="border:1px solid #ccc; max-height:150px; overflow-y:auto; ' +
                                       'display:none; background:#fff; position:relative; z-index:10;"></div>' +
                        '</div>'
                    );
                    $wrapper.append($rowVuota);
                }

                if (response.kits && response.kits.length) {
                    response.kits.forEach(function(kitData) {
                        var kitDef = (BSN_KITS_CACHE || []).find(function(k) {
                            return parseInt(k.id, 10) === parseInt(kitData.kit_id, 10);
                        });
                        var optionsMap = kitDef ? bsnBuildKitOptionsMap(kitDef) : {};

                        var instanceId = 'kit-' + (++BSN_KIT_INSTANCE_COUNTER);
                        var components = (kitData.components || []).map(function(comp) {
                            var ruolo = comp.ruolo || '';
                            var gruppo = comp.gruppo_equivalenza || '';
                            var options = (optionsMap[ruolo] && optionsMap[ruolo][gruppo]) ? optionsMap[ruolo][gruppo] : [parseInt(comp.articolo_id, 10)];
                            var isSelectable = false;
                            if (kitDef) {
                                var match = (kitDef.components || []).find(function(def) {
                                    return def.ruolo === ruolo && def.gruppo_equivalenza === gruppo && parseInt(def.articolo_id, 10) === parseInt(comp.articolo_id, 10);
                                });
                                if (match) {
                                    isSelectable = parseInt(match.is_selectable, 10) === 1;
                                }
                            }
                            return {
                                ruolo: ruolo,
                                gruppo_equivalenza: gruppo,
                                qty: parseInt(comp.qty, 10) || 1,
                                articolo_id: parseInt(comp.articolo_id, 10),
                                is_selectable: isSelectable,
                                options: options
                            };
                        });

                        var instance = {
                            instance_id: instanceId,
                            kit_id: parseInt(kitData.kit_id, 10),
                            kit_label: kitData.kit_label || kitData.nome_kit || '',
                            articolo_kit_id: parseInt(kitData.articolo_kit_id, 10),
                            components: components
                        };

                        var $rowMatch = $('#bsn-noleggio-articoli-wrapper .bsn-noleggio-articolo-row').filter(function() {
                            var idRiga = parseInt($(this).find('.bsn-noleggio-articolo-id').val(), 10);
                            return idRiga === instance.articolo_kit_id && !$(this).data('kit-instance-id');
                        }).first();

                        if ($rowMatch.length) {
                            $rowMatch.data('kit-instance-id', instanceId);
                        }

                        BSN_NOLEGGIO_KITS.push(instance);
                    });
                }

                bsnRenderKitComponenti();
                bsnScheduleKitAvailability();

                // Scroll al form per comodità
                $('html, body').animate({
                    scrollTop: $('#bsn-form-noleggio').offset().top - 20
                }, 300);
            },
            error: function(err) {
                console.error(err);
                alert('Errore nel caricare i dettagli del noleggio.');

                // In caso di errore, ricreiamo almeno una riga articolo vuota
                var $rowVuota = $(
                    '<div class="bsn-noleggio-articolo-row">' +
                        '<input type="text" class="bsn-noleggio-articolo-search" ' +
                            'placeholder="Cerca articolo per nome o codice..." autocomplete="off">' +
                        '<input type="hidden" class="bsn-noleggio-articolo-id" name="articoli_id[]">' +
                        '<input type="number" class="bsn-noleggio-articolo-prezzo" ' +
                            'name="articoli_prezzo[]" min="0" step="0.01" placeholder="Prezzo" ' +
                            'style="max-width:90px;">' +
                        '<input type="number" class="bsn-noleggio-articolo-qty" ' +
                            'name="articoli_qty[]" min="1" value="1" style="max-width:60px;">' +
                        '<button type="button" class="btn bsn-noleggio-articolo-remove">X</button>' +
                        '<div class="bsn-noleggio-articoli-risultati" ' +
                            'style="border:1px solid #ccc; max-height:150px; overflow-y:auto; ' +
                                   'display:none; background:#fff; position:relative; z-index:10;"></div>' +
                    '</div>'
                );
                $wrapper.append($rowVuota);

                $('html, body').animate({
                    scrollTop: $('#bsn-form-noleggio').offset().top - 20
                }, 300);
            }
        });
    });
    /*
    // Cambio stato da menu a tendina (usa endpoint /noleggi/stato)

    $(document).on('change', '.bsn-noleggio-stato-select', function() {
        var $select    = $(this);
        var nuovoStato = $select.val();
        var $row       = $select.closest('.bsn-noleggio-row');
        var id         = $row.data('id');

        if (!id || !nuovoStato) return;

        if (!confirm('Vuoi impostare il noleggio #' + id + ' come "' + nuovoStato + '"?')) {
            $select.val('');
            return;
        }

        $.ajax({
            url: BSN_API.root + 'noleggi/stato',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: {
                id_noleggio: id,
                nuovo_stato: nuovoStato
            },
            success: function(response) {
                alert('Stato noleggio aggiornato a "' + nuovoStato + '".');
                bsnCaricaNoleggi();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nell\'aggiornare lo stato del noleggio.');
                $select.val('');
            }
        });
    });
    */

    // Click su "Rientro / Chiudi" nella tabella noleggi → apre pagina frontend Rientro
    $(document).on('click', '.bsn-noleggio-rientro', function() {
        var $row = $(this).closest('.bsn-noleggio-row');
        var id   = $row.data('id');

        if (!id) return;

        var urlRientro = window.location.origin +
            '/rientro/?id=' +
            encodeURIComponent(id);

        window.location.href = urlRientro;
    });

    // Invia preventivo al cliente (apre anteprima)
    $(document).on('click', '.bsn-noleggio-invia-preventivo', function() {
        var $row = $(this).closest('.bsn-noleggio-row');
        var id   = $row.data('id');

        if (!id) return;

        var basePreventivo = (BSN_API && BSN_API.preventivo_url) ? BSN_API.preventivo_url : (window.location.origin + '/preventivo/');
        var separator = basePreventivo.indexOf('?') === -1 ? '?' : '&';
        var urlPreventivo = basePreventivo + separator + 'id=' + encodeURIComponent(id);

        window.location.href = urlPreventivo;
    });

    // Scorciatoia ticket da noleggio chiuso
    $(document).on('click', '.bsn-noleggio-ticket-shortcut', function() {
        var noleggioId = $(this).data('noleggio-id');
        if (!noleggioId) return;

        $('.tab-btn[data-tab="ticket"]').trigger('click');
        $('#bsn-ticket-search').val(noleggioId).trigger('input');
    });


    // Duplica noleggio
    $(document).on('click', '.bsn-noleggio-duplica', function() {
        console.log('Click su duplica'); // DEBUG␊

        var $row = $(this).closest('.bsn-noleggio-row');
        var id   = $row.data('id');

        if (!id) return;

        if (!confirm('Vuoi duplicare il noleggio #' + id + ' come nuova bozza?')) {
            return;
        }

        $.ajax({
            url: BSN_API.root + 'noleggi/duplica',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: { id: id },
            success: function(response) {
                console.log('duplica OK', response);
                if (response && response.id) {
                    alert('Noleggio duplicato correttamente. Nuovo ID: ' + response.id);
                } else {
                    alert('Noleggio duplicato correttamente.');
                }
                bsnCaricaNoleggi();
            },
            error: function(err) {
                console.error('errore duplica', err);
                alert('Errore nel duplicare il noleggio.');
            }
        });
    });

    function bsnRenderRisultatiArticoliTicket(risultati) {
        var $box = $('#bsn-ticket-articoli-risultati');
        $box.empty();

        if (!risultati.length) {
            bsnResetSearchDropdownActive($box, '.bsn-risultato-articolo');
            $box.append('<div style="padding:5px;">Nessun articolo trovato.</div>');
            $box.show();
            return;
        }

        risultati.slice(0, 20).forEach(function(a) {
            var label = '';
            if (a.codice) {
                label += '[' + a.codice + '] ';
            }
            label += (a.nome || ('Articolo #' + a.id));
            label += ' (ID ' + a.id + ')';

            $box.append(
                '<div class="bsn-ticket-risultato-articolo" data-id="' + a.id + '" data-codice="' + (a.codice || '') + '" data-nome="' + (a.nome || '') + '" ' +
                'style="padding:5px; cursor:pointer; border-bottom:1px solid #eee;">' +
                label +
                '</div>'
            );
        });

        $box.show();
        bsnSetSearchDropdownActive($box, '.bsn-risultato-articolo', 0);
    }

    function bsnSearchArticoliTicket(query, done) {
        $.ajax({
            url: BSN_API.root + 'articoli/search',
            method: 'GET',
            data: { search: query, limit: 20 },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var items = (response && response.articoli) ? response.articoli : [];
                bsnMergeArticoliCache(items);
                done(items);
            },
            error: function() {
                done([]);
            }
        });
    }

    $(document).on('input', '#bsn-ticket-articolo-search', function() {
        var $input = $(this);
        var query = ($input.val() || '').trim();
        var $box = $('#bsn-ticket-articoli-risultati');
        $('#bsn-ticket-articolo-id').val('');

        if (!query || query.length < 2) {
            bsnResetSearchDropdownActive($box, '.bsn-ticket-risultato-articolo');
            $box.hide().empty();
            $box.removeData('bsnTicketResults');
            return;
        }

        var debounceId = $input.data('bsnTicketSearchDebounce');
        if (debounceId) {
            clearTimeout(debounceId);
        }

        var queryAtRequest = query;
        $input.data('bsnTicketSearchDebounce', setTimeout(function() {
            bsnSearchArticoliTicket(queryAtRequest, function(risultati) {
                var currentQuery = ($input.val() || '').trim();
                var hasSelectedId = !!($('#bsn-ticket-articolo-id').val() || '').trim();
                if (currentQuery !== queryAtRequest || hasSelectedId) {
                    return;
                }
                $box.data('bsnTicketResults', risultati);
                bsnRenderRisultatiArticoliTicket(risultati);
            });
        }, 300));
    });

    $(document).on('mousedown', '.bsn-ticket-risultato-articolo', function(e) {
        e.preventDefault();

        var id = parseInt($(this).data('id'), 10);
        var codice = $(this).data('codice') || '';
        var nome = $(this).data('nome') || '';
        var label = '';
        if (codice) {
            label += '[' + codice + '] ';
        }
        label += nome || ('Articolo #' + id);
        label += ' (ID ' + id + ')';

        $('#bsn-ticket-articolo-id').val(id);
        $('#bsn-ticket-articolo-search').val(label);
        bsnResetSearchDropdownActive($('#bsn-ticket-articoli-risultati'), '.bsn-ticket-risultato-articolo');
        $('#bsn-ticket-articoli-risultati').hide().empty();
    });

    $(document).on('mouseenter', '.bsn-ticket-risultato-articolo', function() {
        bsnSyncSearchDropdownHover($(this), '.bsn-ticket-risultato-articolo');
    });

    $(document).on('keydown', '#bsn-ticket-articolo-search', function(e) {
        if (bsnHandleSearchDropdownKey(e, {
            box: $('#bsn-ticket-articoli-risultati'),
            itemSelector: '.bsn-ticket-risultato-articolo'
        })) {
            return;
        }
    });

    $(document).on('blur', '#bsn-ticket-articolo-search', function() {
        setTimeout(function() {
            bsnResetSearchDropdownActive($('#bsn-ticket-articoli-risultati'), '.bsn-ticket-risultato-articolo');
            $('#bsn-ticket-articoli-risultati').hide();
        }, 200);
    });

    var BSN_TICKETS_CACHE = [];

    // Aggiorna la preview delle foto ticket nel form
    function bsnAggiornaPreviewTicketFoto(urls) {
        var $preview = $('#bsn-ticket-foto-preview');
        $preview.empty();

        if (!urls || !urls.length) {
            return;
        }

        var html = '<div style="display:flex; flex-wrap:wrap; gap:6px;">';
        urls.forEach(function(url) {
            if (!url) {
                return;
            }
            var safeUrl = encodeURI(url);
            html += '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' +
                '<img src="' + safeUrl + '" alt="Foto ticket" style="width:60px; height:60px; object-fit:cover; border:1px solid #ccc; border-radius:4px;">' +
            '</a>';
        });
        html += '</div>';
        $preview.html(html);
    }

    // Carica un singolo file foto per ticket e restituisce una Promise con l'URL
    function bsnUploadTicketFoto(file) {
        var formData = new FormData();
        formData.append('file', file);

        return $.ajax({
            url: BSN_API.root + 'upload-doc',
            method: 'POST',
            processData: false,
            contentType: false,
            data: formData,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            }
        }).then(function(response) {
            return response && response.url ? response.url : '';
        });
    }

    // Salva una foto ticket nella lista e aggiorna la preview
    function bsnTicketAggiungiFotoUrl(url) {
        if (!url) {
            return;
        }

        var current = [];
        if ($('#bsn-ticket-foto-urls').val()) {
            try {
                current = JSON.parse($('#bsn-ticket-foto-urls').val()) || [];
            } catch (e) {
                current = [];
            }
        }

        current.push(url);
        $('#bsn-ticket-foto-urls').val(JSON.stringify(current));
        bsnAggiornaPreviewTicketFoto(current);
    }

    // Webcam ticket (riuso logica documento clienti)
    let bsnTicketWebcamStream = null;

    function bsnTicketAvviaWebcam() {
        const video = document.getElementById('bsn-ticket-webcam-video');
        const area = document.getElementById('bsn-ticket-webcam-area');
        const btnCapture = document.getElementById('bsn-ticket-webcam-capture');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('La webcam non è supportata da questo browser.');
            return;
        }

        var constraints = { video: { facingMode: { ideal: 'environment' } } };
        navigator.mediaDevices.getUserMedia(constraints)
            .catch(function() {
                return navigator.mediaDevices.getUserMedia({ video: true });
            })
            .then(function(stream) {
                bsnTicketWebcamStream = stream;
                video.srcObject = stream;
                area.style.display = 'block';
                btnCapture.style.display = 'inline-block';
            })
            .catch(function(err) {
                console.error(err);
                alert('Impossibile accedere alla webcam.');
            });
    }

    function bsnTicketScattaWebcam() {
        const video = document.getElementById('bsn-ticket-webcam-video');
        const canvas = document.getElementById('bsn-ticket-webcam-canvas');
        const ctx = canvas.getContext('2d');

        const width = 640;
        const height = 480;
        canvas.width = width;
        canvas.height = height;
        ctx.drawImage(video, 0, 0, width, height);

        canvas.toBlob(function(blob) {
            if (!blob) {
                alert('Errore nello scatto della foto.');
                return;
            }

            var formData = new FormData();
            formData.append('file', blob, 'ticket-webcam.jpg');

            $.ajax({
                url: BSN_API.root + 'upload-doc',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
                },
                success: function(response) {
                    if (response && response.url) {
                        bsnTicketAggiungiFotoUrl(response.url);
                        if (bsnTicketWebcamStream) {
                            bsnTicketWebcamStream.getTracks().forEach(function(t) { t.stop(); });
                            bsnTicketWebcamStream = null;
                        }
                        $('#bsn-ticket-webcam-area').hide();
                        $('#bsn-ticket-webcam-capture').hide();
                    } else {
                        alert('Upload da webcam riuscito ma URL non trovato.');
                    }
                },
                error: function(err) {
                    console.error(err);
                    alert('Errore nell\'upload della foto ticket (webcam).');
                }
            });
        }, 'image/jpeg', 0.8);
    }

    // Filtra i ticket lato client (stato + testo)
    function bsnFiltraTickets() {
        var testo = ($('#bsn-ticket-search').val() || '').toLowerCase();
        var stato = ($('#bsn-ticket-filtro-stato').val() || '').toLowerCase();

        $('#bsn-ticket-table tbody tr.bsn-ticket-row').each(function() {
            var $row = $(this);
            var rowState = ($row.data('stato') || '').toString().toLowerCase();
            var haystack = ($row.data('search') || '').toString().toLowerCase();

            var matchTesto = !testo || haystack.indexOf(testo) !== -1;
            var matchStato = !stato || rowState === stato;
            var visibile = matchTesto && matchStato;

            $row.toggle(visibile);
            var id = $row.data('ticket-id');
            var $details = $('.bsn-ticket-row-details[data-ticket-id="' + id + '"]');
            if (!visibile) {
                $details.hide();
            }
        });
    }

    function bsnCaricaTickets() {
        $.ajax({
            url: BSN_API.root + 'tickets',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var wrapper = $('#bsn-lista-ticket');
                wrapper.empty();

                if (!response.tickets || !response.tickets.length) {
                    wrapper.html('<p>Nessun ticket salvato.</p>');
                    return;
                }

                BSN_TICKETS_CACHE = response.tickets || [];

                var html = '' +
                    '<table class="bsn-tabella" id="bsn-ticket-table">' +
                        '<thead><tr>' +
                            '<th>ID</th>' +
                            '<th>Articolo</th>' +
                            '<th>Quantità</th>' +
                            '<th>Tipo problema</th>' +
                            '<th>Noleggio</th>' +
                            '<th>Stato</th>' +
                            '<th>Azioni</th>' +
                        '</tr></thead>' +
                        '<tbody>';

                var lista = response.tickets || [];
                var statoFiltro = $('#bsn-ticket-filtro-stato').val();
                var listaFiltrata = lista.filter(function(t) {
                    if (!statoFiltro) {
                        return true;
                    }
                    return t.stato === statoFiltro;
                });
                var listaLimitata = listaFiltrata.slice(0, 12);

                listaLimitata.forEach(function(t) {
                    var articoloLabel = '';
                    if (t.articolo_codice) {
                        articoloLabel += '[' + t.articolo_codice + '] ';
                    }
                    articoloLabel += (t.articolo_nome || ('Articolo #' + t.articolo_id));
                    
                    var rowClass = t.origine === 'rientro' ? ' style="background:#fff3cd;"' : '';
                    var searchData = [
                        t.id,
                        articoloLabel,
                        t.tipo,
                        t.stato,
                        t.note || '',
                        t.noleggio_id || ''
                    ].join(' ').toLowerCase();

                    html += '' +
                        '<tr class="bsn-ticket-row" data-ticket-id="' + t.id + '" data-stato="' + (t.stato || '') + '" data-search="' + searchData + '"' + rowClass + '>' +
                            '<td>' + t.id + '</td>' +
                            '<td>' + articoloLabel + '</td>' +
                            '<td>' + t.qty + '</td>' +
                            '<td>' + t.tipo + '</td>' +
                            '<td>' + (t.noleggio_id || '-') + '</td>' +
                            '<td>' + t.stato + '</td>' +
                            '<td>' +
                                '<div class="bsn-azioni-grid">' +
                                    '<button type="button" class="btn bsn-ticket-details">🔍 Dettagli</button>' +
                                    '<button type="button" class="btn bsn-ticket-edit">✏️ Modifica</button>' +
                                    (t.stato === 'aperto'
                                        ? '<button type="button" class="btn bsn-ticket-chiudi">✅ Chiudi</button>'
                                        : '-') +
                                '</div>' +
                            '</td>' +
                        '</tr>' +
                        '<tr class="bsn-ticket-row-details" data-ticket-id="' + t.id + '" style="display:none;">' +
                            '<td colspan="7">' +
                                '<div class="bsn-ticket-details-container"></div>' +
                            '</td>' +
                        '</tr>';
                });

                html += '</tbody></table>';
                wrapper.html(html);
                bsnFiltraTickets();
            },
            error: function(err) {
                console.error(err);
                $('#bsn-lista-ticket').html('<p>Errore nel caricare i ticket.</p>');
            }
        });
    }

    $('#bsn-form-ticket').on('submit', function(e) {
        e.preventDefault();

        var ticketId  = $('#bsn-ticket-id').val();
        var articoloId = $('#bsn-ticket-articolo-id').val();
        var qty        = parseInt($('#bsn-ticket-qty').val(), 10) || 0;
        var tipo       = $('#bsn-ticket-tipo').val();
        var stato      = $('#bsn-ticket-stato').val();
        var noleggioId = $('#bsn-ticket-noleggio').val().trim();
        var note       = $('#bsn-ticket-note').val().trim();
        var fotoFiles  = $('#bsn-ticket-foto')[0].files || [];
        var fotoUrls   = [];

        if ($('#bsn-ticket-foto-urls').val()) {
            try {
                fotoUrls = JSON.parse($('#bsn-ticket-foto-urls').val()) || [];
            } catch (e) {
                fotoUrls = [];
            }
        }

        if (!articoloId) {
            alert('Seleziona un articolo dalla ricerca.');
            return;
        }

        if (qty <= 0) {
            alert('Inserisci una quantità valida.');
            return;
        }

        var isUpdate = ticketId && ticketId.length > 0;
        var uploadPromises = [];

        if (fotoFiles.length) {
            Array.prototype.forEach.call(fotoFiles, function(file) {
                uploadPromises.push(bsnUploadTicketFoto(file));
            });
        }

        $.when.apply($, uploadPromises).done(function() {
            var nuoviUrl = [];
            if (uploadPromises.length === 1) {
                nuoviUrl = [arguments[0]];
            } else if (uploadPromises.length > 1) {
                nuoviUrl = Array.prototype.slice.call(arguments).map(function(result) {
                    return result;
                });
            }

            var fotoFinali = fotoUrls.concat(nuoviUrl).filter(Boolean);

            $.ajax({
                url: BSN_API.root + (isUpdate ? 'tickets/update' : 'tickets'),
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
                },
                data: {
                    ticket_id: ticketId,
                    articolo_id: articoloId,
                    qty: qty,
                    tipo: tipo,
                    note: note,
                    noleggio_id: noleggioId,
                    stato: stato,
                    foto: JSON.stringify(fotoFinali)
                },
                success: function() {
                    alert(isUpdate ? 'Ticket aggiornato correttamente.' : 'Ticket creato correttamente.');
                    $('#bsn-form-ticket')[0].reset();
                    $('#bsn-ticket-id').val('');
                    $('#bsn-ticket-stato').val('aperto');
                    $('#bsn-ticket-articolo-id').val('');
                    $('#bsn-ticket-articoli-risultati').hide().empty();
                    $('#bsn-ticket-articolo-search').prop('disabled', false);
                    $('#bsn-ticket-foto-urls').val('');
                    bsnAggiornaPreviewTicketFoto([]);
                    bsnCaricaTickets();
                    bsnCaricaArticoli();;
                },
                error: function(err) {
                    console.error(err);
                    alert('Errore nel salvataggio del ticket.');
                }
            });
        }).fail(function(err) {
            console.error(err);
            alert('Errore nel caricamento delle foto.');
        });
    });

    $(document).on('click', '.bsn-ticket-edit', function() {
        var ticketId = $(this).closest('tr').data('ticket-id');
        var ticket = (BSN_TICKETS_CACHE || []).find(function(t) {
            return parseInt(t.id, 10) === parseInt(ticketId, 10);
        });
        if (!ticket) {
            alert('Ticket non trovato.');
            return;
        }

        $('#bsn-ticket-id').val(ticket.id);
        $('#bsn-ticket-articolo-id').val(ticket.articolo_id);
        $('#bsn-ticket-articolo-search').val(ticket.articolo_codice ? '[' + ticket.articolo_codice + '] ' + ticket.articolo_nome : ticket.articolo_nome);
        $('#bsn-ticket-articolo-search').prop('disabled', true);
        $('#bsn-ticket-qty').val(ticket.qty);
        $('#bsn-ticket-tipo').val(ticket.tipo);
        $('#bsn-ticket-stato').val(ticket.stato);
        $('#bsn-ticket-noleggio').val(ticket.noleggio_id || '');
        $('#bsn-ticket-note').val(ticket.note || '');
        $('#bsn-ticket-foto-urls').val(ticket.foto || '');

        var fotoParsed = [];
        if (ticket.foto) {
            try {
                fotoParsed = JSON.parse(ticket.foto) || [];
            } catch (e) {
                fotoParsed = [];
            }
        }
        bsnAggiornaPreviewTicketFoto(fotoParsed);
    });

    // Dettagli ticket: mostra note complete e foto
    $(document).on('click', '.bsn-ticket-details', function() {
        var $row = $(this).closest('tr');
        var ticketId = $row.data('ticket-id');
        var ticket = (BSN_TICKETS_CACHE || []).find(function(t) {
            return parseInt(t.id, 10) === parseInt(ticketId, 10);
        });
        var $detailsRow = $('.bsn-ticket-row-details[data-ticket-id="' + ticketId + '"]');
        var $container = $detailsRow.find('.bsn-ticket-details-container');

        if ($detailsRow.is(':visible')) {
            $detailsRow.hide();
            return;
        }

        if (!ticket) {
            $container.html('<em>Dati ticket non trovati.</em>');
            $detailsRow.show();
            return;
        }

        var fotoHtml = '<em>Nessuna foto</em>';
        if (ticket.foto) {
            try {
                var fotoList = JSON.parse(ticket.foto) || [];
                if (fotoList.length) {
                    fotoHtml = '<div style="display:flex; flex-wrap:wrap; gap:6px;">' +
                        fotoList.map(function(url) {
                            var safeUrl = encodeURI(url);
                            return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' +
                                '<img src="' + safeUrl + '" alt="Foto ticket" style="width:80px; height:80px; object-fit:cover; border:1px solid #ccc; border-radius:4px;">' +
                            '</a>';
                        }).join('') +
                        '</div>';
                }
            } catch (e) {
                fotoHtml = '<em>Errore nel caricamento foto.</em>';
            }
        }

        var html = '' +
            '<div style="margin-bottom:8px;"><strong>Note complete:</strong><br>' +
                (ticket.note ? ticket.note : '-') +
            '</div>' +
            '<div><strong>Foto:</strong><br>' + fotoHtml + '</div>';

        $container.html(html);
        $detailsRow.show();
    });
    
    $(document).on('click', '.bsn-ticket-chiudi', function() {
        var ticketId = $(this).closest('tr').data('ticket-id');
        if (!ticketId) return;

        if (!confirm('Chiudere questo ticket?')) {
            return;
        }

        $.ajax({
            url: BSN_API.root + 'tickets/chiudi',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: {
                ticket_id: ticketId
            },
            success: function() {
                alert('Ticket chiuso.');
                bsnCaricaTickets();
                bsnCaricaArticoli();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nella chiusura del ticket.');
            }
        });
        });

        $(document).on('click', '#bsn-ticket-webcam-start', function() {
        bsnTicketAvviaWebcam();
        });

        $(document).on('click', '#bsn-ticket-webcam-capture', function() {
        bsnTicketScattaWebcam();
        });

        $(document).on('input', '#bsn-ticket-search', function() {
            bsnFiltraTickets();
        });

        $(document).on('change', '#bsn-ticket-filtro-stato', function() {
            bsnCaricaTickets();
        });

    // Elimina noleggio
    $(document).on('click', '.bsn-noleggio-delete', function() {
        var $row = $(this).closest('.bsn-noleggio-row');
        var id   = $row.data('id');

        if (!id) return;

        if (!confirm('Vuoi eliminare definitivamente il noleggio #' + id + '?')) {
            return;
        }

        $.ajax({
            url: BSN_API.root + 'noleggi/delete',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            data: { id: id },
            success: function(response) {
                alert('Noleggio eliminato.');
                bsnCaricaNoleggi();
            },
            error: function(err) {
                console.error(err);
                alert('Errore nell\'eliminazione noleggio.');
            }
        });
    });

    $(document).on('change', '#bsn-filtro-stato', function() {
        bsnListState.noleggi.stato = ($(this).val() || '').toLowerCase();
        bsnCaricaNoleggi();
    });

    $(document).on('input', '#bsn-noleggi-search', function() {
        bsnListState.noleggi.search = ($(this).val() || '').trim();
        bsnDebounce(function() {
            bsnCaricaNoleggi();
        }, 320, bsnListState.noleggi, 'debounce');
    });

    $(document).on('change', '#bsn-noleggi-limit', function() {
        bsnListState.noleggi.limit = parseInt($(this).val(), 10) || 12;
        bsnCaricaNoleggi();
    });



    function bsnCaricaEtichette() {
        $.ajax({
            url: BSN_API.root + 'articoli/etichette',
            method: 'GET',
            data: {
                search: bsnListState.etichette.search,
                limit: bsnListState.etichette.limit,
                only_unprinted: bsnListState.etichette.onlyUnprinted
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                var items = response.articoli || [];
                var $wrap = $('#bsn-lista-etichette');
                $wrap.empty();

                if (!items.length) {
                    $wrap.html('<p>Nessun articolo trovato.</p>');
                    return;
                }

                var allChecked = items.length > 0 && items.every(function(a) { return !!bsnListState.etichette.selected[a.id]; });

                var html = '<table class="bsn-tabella" id="bsn-etichette-table"><thead><tr>' +
                    '<th><input type="checkbox" id="bsn-etichette-select-all" ' + (allChecked ? 'checked' : '') + '></th>' +
                    '<th>COPIE</th><th>ID</th><th>Nome</th><th>Codice</th><th>Data creazione</th><th>Ultima modifica</th><th>Stampato</th>' +
                    '</tr></thead><tbody>';

                items.forEach(function(a) {
                    var checked = bsnListState.etichette.selected[a.id] ? 'checked' : '';
                    var copies = parseInt(bsnListState.etichette.copies[a.id], 10);
                    if (!copies || copies < 1) copies = 1;
                    if (copies > 100) copies = 100;
                    var isPrinted = parseInt(a.qr_stampato, 10) === 1;
                    var modified = (a.data_modifica && a.data_modifica !== '0000-00-00 00:00:00') ? a.data_modifica : '—';
                    html += '<tr>' +
                        '<td><input type="checkbox" class="bsn-etichette-select" data-id="' + a.id + '" ' + checked + '></td>' +
                        '<td><input type="number" class="bsn-etichette-copies" data-id="' + a.id + '" min="1" max="100" step="1" value="' + copies + '"></td>' +
                        '<td>' + a.id + '</td>' +
                        '<td>' + (a.nome || '') + '</td>' +
                        '<td>' + (a.codice || '') + '</td>' +
                        '<td>' + (a.data_creazione || '—') + '</td>' +
                        '<td>' + modified + '</td>' +
                        '<td><label class="bsn-etichette-toggle"><input type="checkbox" class="bsn-etichette-printed-toggle" data-id="' + a.id + '" ' + (isPrinted ? 'checked' : '') + '> <span>' + (isPrinted ? 'STAMPATO' : 'NON STAMPATO') + '</span></label></td>' +
                    '</tr>';
                });

                html += '</tbody></table>';
                $wrap.html(html);
            },
            error: function(err) {
                console.error(err);
                $('#bsn-lista-etichette').html('<p>Errore nel caricamento etichette.</p>');
            }
        });
    }

    $(document).on('input', '#bsn-etichette-search', function() {
        bsnListState.etichette.search = ($(this).val() || '').trim();
        bsnDebounce(function() { bsnCaricaEtichette(); }, 320, bsnListState.etichette, 'debounce');
    });

    $(document).on('change', '#bsn-etichette-limit', function() {
        bsnListState.etichette.limit = parseInt($(this).val(), 10) || 12;
        bsnCaricaEtichette();
    });

    $(document).on('change', '#bsn-etichette-only-unprinted', function() {
        bsnListState.etichette.onlyUnprinted = $(this).is(':checked') ? 1 : 0;
        bsnCaricaEtichette();
    });

    $(document).on('change', '#bsn-etichette-select-all', function() {
        var checked = $(this).is(':checked');
         $('.bsn-etichette-select').each(function() {
            var id = parseInt($(this).data('id'), 10);
            $(this).prop('checked', checked);
            if (checked) {
                bsnListState.etichette.selected[id] = true;
            } else {
                delete bsnListState.etichette.selected[id];
            }
        });
    });

    $(document).on('change', '.bsn-etichette-select', function() {
        var id = parseInt($(this).data('id'), 10);
        if ($(this).is(':checked')) {
            bsnListState.etichette.selected[id] = true;
        } else {
             delete bsnListState.etichette.selected[id];
        }
    });

    $(document).on('input', '.bsn-etichette-copies', function() {
        var id = parseInt($(this).data('id'), 10);
        var copies = parseInt($(this).val(), 10) || 1;
        if (copies < 1) copies = 1;
        if (copies > 100) copies = 100;
        $(this).val(copies);
        bsnListState.etichette.copies[id] = copies;
    });

    function bsnEtichetteIdsSelezionati() {
        return Object.keys(bsnListState.etichette.selected).map(function(id) { return parseInt(id, 10); });
    }

    function bsnScaricaFileDaUrl(url, filename) {
        if (!url) return;

        // Evita popup bloccati: scarico via fetch+blob e trigger con <a download>
        fetch(url, { credentials: 'same-origin' })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.blob();
            })
            .then(function(blob) {
                var objectUrl = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = objectUrl;
                a.download = filename || 'bsn-etichette.csv';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function() {
                    window.URL.revokeObjectURL(objectUrl);
                }, 1000);
            })
            .catch(function(err) {
                console.error(err);
                // Fallback: navigazione diretta
                window.location.href = url;
            });
    }

    function bsnScaricaBase64Csv(base64Data, filename, mime) {
        if (!base64Data) return;

        var raw = window.atob(base64Data);
        var len = raw.length;
        var bytes = new Uint8Array(len);
        for (var i = 0; i < len; i++) {
            bytes[i] = raw.charCodeAt(i);
        }

        var blob = new Blob([bytes], { type: mime || 'text/csv;charset=utf-8' });
        var objectUrl = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = objectUrl;
        a.download = filename || 'bsn-etichette.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function() {
            window.URL.revokeObjectURL(objectUrl);
        }, 1000);
    }

    function bsnEtichetteSetPrinted(printed) {
        var ids = bsnEtichetteIdsSelezionati();
        if (!ids.length) {
            alert('Seleziona almeno un articolo.');
            return;
        }

        $.ajax({
             url: BSN_API.root + 'articoli/etichette-stato',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ ids: ids, printed: printed ? true : false }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function() {
                bsnCaricaEtichette();
            },
            error: function(err) {
                console.error(err);
                alert('Errore aggiornamento stato stampato.');
            }
        });
    }

    $(document).on('change', '.bsn-etichette-printed-toggle', function() {
        var id = parseInt($(this).data('id'), 10);
        var printed = $(this).is(':checked');
        $.ajax({
            url: BSN_API.root + 'articoli/etichette-stato',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ ids: [id], printed: printed }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function() {
                bsnCaricaEtichette();
            },
            error: function(err) {
                console.error(err);
                alert('Errore aggiornamento stato stampato.');
                bsnCaricaEtichette();
            }
        });
    });

    $(document).on('click', '#bsn-etichette-mark-printed', function() { bsnEtichetteSetPrinted(true); });
    $(document).on('click', '#bsn-etichette-mark-unprinted', function() { bsnEtichetteSetPrinted(false); });

    $(document).on('click', '#bsn-etichette-clear-selection', function() {
        bsnListState.etichette.selected = {};
        bsnCaricaEtichette();
    });

    $(document).on('click', '#bsn-etichette-export', function() {
        var ids = bsnEtichetteIdsSelezionati();
        if (!ids.length) {
            alert('Seleziona almeno un articolo.');
            return;
        }

    var items = ids.map(function(id) {
            var copies = parseInt(bsnListState.etichette.copies[id], 10) || 1;
            if (copies < 1) copies = 1;
            if (copies > 100) copies = 100;
            return { id: id, copies: copies };
        });

        $.ajax({
            url: BSN_API.root + 'articoli/etichette-export',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ items: items }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
            },
            success: function(response) {
                if (response && response.csv_base64) {
                    bsnScaricaBase64Csv(
                        response.csv_base64,
                        response.filename || 'bsn-etichette.csv',
                        response.mime || 'text/csv;charset=utf-8'
                    );
                } else if (response && response.url) {
                    bsnScaricaFileDaUrl(response.url, response.filename || 'bsn-etichette.csv');
                } else {
                    alert('Export completato ma file non disponibile.');
                }
                bsnCaricaEtichette();
            },
            error: function(err) {
                console.error(err);
                alert('Errore durante export CSV etichette.');
            }
        });
    });

    /* ==== CALENDARIO NOLEGGI ==== */

    var bsnCalendarState = {
        view: 'month',
        currentDate: new Date(),
        rentals: [],
        filter: ''
    };

    var $calendarGrid = $('#bsn-calendar-grid');
    var $calendarDay = $('#bsn-calendar-day');
    var $calendarTitle = $('#bsn-calendar-title');
    var $calendarPrev = $('#bsn-calendar-prev');
    var $calendarNext = $('#bsn-calendar-next');
    var $calendarSearch = $('#bsn-calendar-search');
    var $calendarViews = $('.bsn-calendar-view');

    function bsnStartOfWeek(date) {
        var day = (date.getDay() + 6) % 7; // Monday = 0
        var result = new Date(date);
        result.setDate(result.getDate() - day);
        result.setHours(0, 0, 0, 0);
        return result;
    }

    function bsnEndOfWeek(date) {
        var result = bsnStartOfWeek(date);
        result.setDate(result.getDate() + 6);
        result.setHours(23, 59, 59, 999);
        return result;
    }

    function bsnAddDays(date, days) {
        var result = new Date(date);
        result.setDate(result.getDate() + days);
        return result;
    }

    function bsnFormatDateDdMmYyyy(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }
        var day = String(date.getDate()).padStart(2, '0');
        var month = String(date.getMonth() + 1).padStart(2, '0');
        return day + '-' + month + '-' + date.getFullYear();
    }

    function bsnFormatDateItalian(date) {
        return bsnFormatDateDdMmYyyy(date);
    }

    function bsnFormatDateFullItalian(date) {
        var weekday = date.toLocaleDateString('it-IT', { weekday: 'long' });
        return weekday + ' ' + bsnFormatDateDdMmYyyy(date);
    }

    function bsnFormatDayLabel(date) {
        var weekday = date.toLocaleDateString('it-IT', { weekday: 'short' });
        return date.getDate() + ' ' + weekday;
    }

    function bsnParseDate(value) {
        if (!value) return null;
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return new Date(value + 'T00:00:00');
        }
        if (/^\d{2}\/\d{2}\/\d{4}$/.test(value)) {
            var parts = value.split('/');
            return new Date(parts[2] + '-' + parts[1] + '-' + parts[0] + 'T00:00:00');
        }
        if (/^\d{2}-\d{2}-\d{4}$/.test(value)) {
            var dashParts = value.split('-');
            return new Date(dashParts[2] + '-' + dashParts[1] + '-' + dashParts[0] + 'T00:00:00');
        }
        var parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return null;
        }
        return parsed;
    }

    function bsnAssignCalendarLanes(rentals, rangeStart, rangeEnd) {
        var ordered = rentals.slice().sort(function(a, b) {
            if (a.start.getTime() === b.start.getTime()) {
                return a.end.getTime() - b.end.getTime();
            }
            return a.start.getTime() - b.start.getTime();
        });

        var laneEnds = [];

        ordered.forEach(function(rental) {
            var start = rental.start < rangeStart ? rangeStart : rental.start;
            var end = rental.end > rangeEnd ? rangeEnd : rental.end;
            var laneIndex = 0;

            for (; laneIndex < laneEnds.length; laneIndex++) {
                if (start.getTime() > laneEnds[laneIndex]) {
                    break;
                }
            }

            if (laneIndex === laneEnds.length) {
                laneEnds.push(end.getTime());
            } else {
                laneEnds[laneIndex] = end.getTime();
            }

            rental._lane = laneIndex + 1;
        });

        return {
            items: ordered,
            laneCount: Math.max(laneEnds.length, 1)
        };
    }

    function bsnFilterCalendarRentals() {
        var term = bsnCalendarState.filter.trim().toLowerCase();
        if (!term) {
            return bsnCalendarState.rentals;
        }
        return bsnCalendarState.rentals.filter(function(rental) {
            return (
                (rental.id || '').toLowerCase().indexOf(term) !== -1 ||
                (rental.cliente_nome || '').toLowerCase().indexOf(term) !== -1 ||
                (rental.articoli_riassunto || '').toLowerCase().indexOf(term) !== -1
            );
        });
    }

    function bsnRenderDayView(date, rentals) {
        $calendarGrid.empty();
        $calendarDay.prop('hidden', false).empty();

        var $title = $('<h3></h3>').text(bsnFormatDateFullItalian(date));
        $calendarDay.append($title);

        var dayRentals = rentals.filter(function(rental) {
            return rental.start && rental.end && rental.start <= date && rental.end >= date;
        });

        if (!dayRentals.length) {
            $calendarDay.append('<p>Nessun noleggio per questa data.</p>');
            return;
        }

        var $list = $('<ul class="bsn-calendar-day-list"></ul>');
        dayRentals.forEach(function(rental) {
            var $item = $('<li></li>').addClass('bsn-calendar-day-item bsn-calendar-event--' + rental.stato);
            var $link = $('<a></a>')
                .attr('href', '/ispeziona/?id=' + encodeURIComponent(rental.id))
                .text(rental.cliente_nome + ' (' + rental.id + ')');
            $item.append($link);
            $item.append('<div>' + bsnFormatDateItalian(rental.start) + ' → ' + bsnFormatDateItalian(rental.end) + '</div>');
            if (rental.articoli_riassunto && rental.articoli_riassunto !== '-') {
                $item.append('<div>' + rental.articoli_riassunto + '</div>');
            }
            $list.append($item);
        });

        $calendarDay.append($list);
    }

    function bsnRenderCalendar() {
        if (!$calendarGrid.length || !$calendarTitle.length) {
            return;
        }

        var rentals = bsnFilterCalendarRentals();
        var current = new Date(bsnCalendarState.currentDate);

        $calendarDay.prop('hidden', true);

        if (bsnCalendarState.view === 'day') {
            $calendarTitle.text(bsnFormatDateItalian(current));
            bsnRenderDayView(current, rentals);
            return;
        }

        $calendarGrid.empty();

        var rangeStart;
        var rangeEnd;

        if (bsnCalendarState.view === 'month') {
            rangeStart = bsnStartOfWeek(new Date(current.getFullYear(), current.getMonth(), 1));
            rangeEnd = bsnEndOfWeek(new Date(current.getFullYear(), current.getMonth() + 1, 0));
            $calendarTitle.text(current.toLocaleDateString('it-IT', {
                month: 'long',
                year: 'numeric'
            }));
        } else {
            var currentWeekStart = bsnStartOfWeek(current);
            rangeStart = bsnAddDays(currentWeekStart, -7);
            rangeEnd = bsnEndOfWeek(bsnAddDays(currentWeekStart, 7));
            $calendarTitle.text('Settimane ' + bsnFormatDateItalian(rangeStart) + ' → ' + bsnFormatDateItalian(rangeEnd));
        }
        
        var rentalsInRange = rentals.filter(function(rental) {
            return rental.start && rental.end && rental.start <= rangeEnd && rental.end >= rangeStart;
        });

        var laneInfo = bsnAssignCalendarLanes(rentalsInRange, rangeStart, rangeEnd);
        var rentalsWithLanes = laneInfo.items;
        var maxLanes = laneInfo.laneCount;
        var weeks = [];
        var cursor = new Date(rangeStart);
        cursor.setHours(0, 0, 0, 0);
        while (cursor <= rangeEnd) {
            weeks.push(new Date(cursor));
            cursor = bsnAddDays(cursor, 7);
        }

        weeks.forEach(function(weekStart) {
            var weekEnd = bsnEndOfWeek(weekStart);
            var $weekWrapper = $('<div class="bsn-calendar-week-row"></div>');
            var $daysRow = $('<div class="bsn-calendar-week"></div>');

            for (var i = 0; i < 7; i += 1) {
                var day = bsnAddDays(weekStart, i);
                var $cell = $('<div class="bsn-calendar-day-cell"></div>');
                if (bsnCalendarState.view === 'month' && day.getMonth() !== current.getMonth()) {
                    $cell.addClass('outside');
                }
                $cell.append('<div class="bsn-calendar-day-number">' + bsnFormatDayLabel(day) + '</div>');
                $daysRow.append($cell);
            }

            var $eventsRow = $('<div class="bsn-calendar-week-events"></div>');
            $eventsRow.css({
                gridTemplateRows: 'repeat(' + maxLanes + ', 26px)',
                minHeight: (maxLanes * 26) + 'px'
            });

            var weekRentals = rentalsWithLanes.filter(function(rental) {
                return rental.start && rental.end && rental.start <= weekEnd && rental.end >= weekStart;
            });

            var segments = weekRentals.map(function(rental) {
                var segStart = rental.start < weekStart ? weekStart : rental.start;
                var segEnd = rental.end > weekEnd ? weekEnd : rental.end;
                var startIndex = Math.floor((segStart - weekStart) / 86400000) + 1;
                var endIndex = Math.floor((segEnd - weekStart) / 86400000) + 1;
                return {
                    rental: rental,
                    startIndex: startIndex,
                    endIndex: endIndex,
                    lane: rental._lane || 1
                };
            });

            segments.sort(function(a, b) {
                if (a.lane === b.lane) {
                    if (a.startIndex === b.startIndex) {
                        return a.endIndex - b.endIndex;
                    }
                    return a.startIndex - b.startIndex;
                }
                return a.lane - b.lane;
            });

            segments.forEach(function(segment) {
                var $event = $('<div class="bsn-calendar-event"></div>');
                $event.addClass('bsn-calendar-event--' + segment.rental.stato);
                $event.css({
                    gridColumn: segment.startIndex + ' / ' + (segment.endIndex + 1),
                    gridRow: segment.lane
                });
                $event.text(segment.rental.cliente_nome);
                $event.attr('title', segment.rental.id + ' • ' + bsnFormatDateItalian(segment.rental.start) + ' → ' + bsnFormatDateItalian(segment.rental.end));
                $event.on('click', function() {
                    window.location.href = '/ispeziona/?id=' + encodeURIComponent(segment.rental.id);
                });
                $eventsRow.append($event);
            });

            $weekWrapper.append($daysRow);
            $weekWrapper.append($eventsRow);
            $calendarGrid.append($weekWrapper);
        });
        // NASCONDI le settimane con SOLO celle outside (vuote)
            if (bsnCalendarState.view === 'month') {
                $('.bsn-calendar-week-row').each(function() {
                    var $daysRow = $(this).find('.bsn-calendar-week');
                    var validDays = $daysRow.find('.bsn-calendar-day-cell:not(.outside)').length;
                    if (validDays === 0) {
                        $(this).remove();
                    }
                });
            }
            // Rimuovi settimane vuote - DOPO il rendering
        setTimeout(function() {
            $('.bsn-calendar-week-row').each(function() {
                var $week = $(this).find('.bsn-calendar-week');
                var validCells = $week.find('.bsn-calendar-day-cell:not(.outside)').length;
                if (validCells === 0) {
                    $(this).css('display', 'none');
                }
            });
        }, 100);
        }
        function bsnUpdateCalendarData(noleggi) {
        if (!Array.isArray(noleggi)) {
            bsnCalendarState.rentals = [];
            bsnRenderCalendar();
            return;
        }

        bsnCalendarState.rentals = noleggi.map(function(item) {
            var start = bsnParseDate(item.data_ritiro_effettiva_raw || item.data_ritiro_raw || item.data_inizio_raw || item.data_da);
            var end = bsnParseDate(item.data_riconsegna_effettiva_raw || item.data_riconsegna_raw || item.data_fine_raw || item.data_a);
            if (!start || !end) {
                return null;
            }
            start.setHours(0, 0, 0, 0);
            end.setHours(0, 0, 0, 0);
            return {
                id: item.id || '',
                cliente_nome: item.cliente_nome || 'Cliente',
                articoli_riassunto: item.articoli_riassunto || '-',
                stato: (item.stato || 'bozza').toLowerCase(),
                start: start,
                end: end
            };
        }).filter(Boolean);

        bsnRenderCalendar();
    }

    function bsnInitCalendarControls() {
        if (!$calendarGrid.length || !$calendarTitle.length) {
            return;
        }

        $calendarPrev.on('click', function() {
            var current = bsnCalendarState.currentDate;
            if (bsnCalendarState.view === 'month') {
                bsnCalendarState.currentDate = new Date(current.getFullYear(), current.getMonth() - 1, 1);
            } else if (bsnCalendarState.view === 'week') {
                bsnCalendarState.currentDate = bsnAddDays(current, -7);
            } else {
                bsnCalendarState.currentDate = bsnAddDays(current, -1);
            }
            bsnRenderCalendar();
        });

        $calendarNext.on('click', function() {
            var current = bsnCalendarState.currentDate;
            if (bsnCalendarState.view === 'month') {
                bsnCalendarState.currentDate = new Date(current.getFullYear(), current.getMonth() + 1, 1);
            } else if (bsnCalendarState.view === 'week') {
                bsnCalendarState.currentDate = bsnAddDays(current, 7);
            } else {
                bsnCalendarState.currentDate = bsnAddDays(current, 1);
            }
            bsnRenderCalendar();
        });

        $calendarViews.on('click', function() {
            var view = $(this).data('view');
            bsnCalendarState.view = view;
            $calendarViews.removeClass('active');
            $(this).addClass('active');
            bsnRenderCalendar();
        });

        $calendarSearch.on('input', function() {
            bsnCalendarState.filter = $(this).val() || '';
            bsnRenderCalendar();
        });
    }

    bsnInitCalendarControls();

    // ==== WEBCAM DOCUMENTO FRONTE ====

    let bsnDocFronteStream = null;

    $(document).on('click', '#bsn-doc-fronte-webcam-start', function() {
        const video = document.getElementById('bsn-doc-fronte-video');
        const area  = document.getElementById('bsn-doc-fronte-webcam-area');
        const btnCapture = document.getElementById('bsn-doc-fronte-webcam-capture');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('La webcam non è supportata da questo browser.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(stream) {
                bsnDocFronteStream = stream;
                video.srcObject = stream;
                area.style.display = 'block';
                btnCapture.style.display = 'inline-block';
            })
            .catch(function(err) {
                console.error(err);
                alert('Impossibile accedere alla webcam.');
            });
    });

    $(document).on('click', '#bsn-doc-fronte-webcam-capture', function() {
        const video  = document.getElementById('bsn-doc-fronte-video');
        const canvas = document.getElementById('bsn-doc-fronte-canvas');
        const ctx    = canvas.getContext('2d');

        // Disegna il frame del video sul canvas (ridimensionato se vuoi)
        const width  = 640;
        const height = 480;
        canvas.width  = width;
        canvas.height = height;
        ctx.drawImage(video, 0, 0, width, height);

        // Converte il canvas in blob e lo manda all'endpoint upload-doc
        canvas.toBlob(function(blob) {
            if (!blob) {
                alert('Errore nello scatto della foto.');
                return;
            }

            var formData = new FormData();
            formData.append('file', blob, 'doc-fronte-webcam.jpg');

            $.ajax({
                url: BSN_API.root + 'upload-doc',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
                },
                success: function(response) {
                    if (response && response.url) {
                        $('#bsn-doc-fronte-path').val(response.url);
                        $('#bsn-doc-fronte-preview').html(
                            '<img src="' + response.url + '" alt="Documento fronte" style="max-width:160px;">'
                        );
                        // ferma la webcam
                        if (bsnDocFronteStream) {
                            bsnDocFronteStream.getTracks().forEach(function(t){ t.stop(); });
                            bsnDocFronteStream = null;
                        }
                        $('#bsn-doc-fronte-webcam-area').hide();
                        $('#bsn-doc-fronte-webcam-capture').hide();
                    } else {
                        alert('Upload da webcam riuscito ma URL non trovato.');
                    }
                },
                error: function(err) {
                    console.error(err);
                    alert('Errore nell\'upload del documento fronte (webcam).');
                }
            });
        }, 'image/jpeg', 0.8);
    });

    // ==== WEBCAM DOCUMENTO RETRO ====

    let bsnDocRetroStream = null;

    $(document).on('click', '#bsn-doc-retro-webcam-start', function() {
        const video = document.getElementById('bsn-doc-retro-video');
        const area  = document.getElementById('bsn-doc-retro-webcam-area');
        const btnCapture = document.getElementById('bsn-doc-retro-webcam-capture');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('La webcam non è supportata da questo browser.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(stream) {
                bsnDocRetroStream = stream;
                video.srcObject = stream;
                area.style.display = 'block';
                btnCapture.style.display = 'inline-block';
            })
            .catch(function(err) {
                console.error(err);
                alert('Impossibile accedere alla webcam.');
            });
    });

    $(document).on('click', '#bsn-doc-retro-webcam-capture', function() {
        const video  = document.getElementById('bsn-doc-retro-video');
        const canvas = document.getElementById('bsn-doc-retro-canvas');
        const ctx    = canvas.getContext('2d');

        const width  = 640;
        const height = 480;
        canvas.width  = width;
        canvas.height = height;
        ctx.drawImage(video, 0, 0, width, height);

        canvas.toBlob(function(blob) {
            if (!blob) {
                alert('Errore nello scatto della foto.');
                return;
            }

            var formData = new FormData();
            formData.append('file', blob, 'doc-retro-webcam.jpg');

            $.ajax({
                url: BSN_API.root + 'upload-doc',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', BSN_API.nonce);
                },
                success: function(response) {
                    if (response && response.url) {
                        $('#bsn-doc-retro-path').val(response.url);
                        $('#bsn-doc-retro-preview').html(
                            '<img src="' + response.url + '" alt="Documento retro" style="max-width:160px;">'
                        );
                        if (bsnDocRetroStream) {
                            bsnDocRetroStream.getTracks().forEach(function(t){ t.stop(); });
                            bsnDocRetroStream = null;
                        }
                        $('#bsn-doc-retro-webcam-area').hide();
                        $('#bsn-doc-retro-webcam-capture').hide();
                    } else {
                        alert('Upload da webcam riuscito ma URL non trovato.');
                    }
                },
                error: function(err) {
                    console.error(err);
                    alert('Errore nell\'upload del documento retro (webcam).');
                }
            });
        }, 'image/jpeg', 0.8);
    });

     /// Avvio iniziale
    bsnCaricaClienti();
    bsnCaricaArticoli();
    bsnCaricaKits();
    bsnAggiungiKitComponenteRow();
    bsnCaricaProfiliSconti();
    bsnCaricaNoleggi();
    bsnCaricaEtichette();
    bsnCaricaTickets();
});
