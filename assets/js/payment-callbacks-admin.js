(function ($) {
    'use strict';

    var state = {
        gateway: 'all',
        startDate: '',
        endDate: '',
        search: '',
        page: 1,
        perPage: 25,
        columns: [],
        total: 0,
        bulkRunning: false
    };

    var bulkState = {
        orderIds: [],
        index: 0,
        batchSize: 5,
        startDate: '',
        endDate: '',
        gateway: ''
    };

    function getDateRangePayload() {
        return {
            start_date: state.startDate,
            end_date: state.endDate
        };
    }

    function readDateInputs() {
        state.startDate = $('#wc-combo-pcb-start-date').val() || wcComboPaymentCallbacks.defaultStartDate;
        state.endDate = $('#wc-combo-pcb-end-date').val() || wcComboPaymentCallbacks.defaultEndDate;

        if (state.startDate && state.endDate && state.startDate > state.endDate) {
            var tmp = state.startDate;
            state.startDate = state.endDate;
            state.endDate = tmp;
            $('#wc-combo-pcb-start-date').val(state.startDate);
            $('#wc-combo-pcb-end-date').val(state.endDate);
        }
    }

    function setDateInputs(startDate, endDate) {
        state.startDate = startDate;
        state.endDate = endDate;
        $('#wc-combo-pcb-start-date').val(startDate);
        $('#wc-combo-pcb-end-date').val(endDate);
    }

    function post(action, data) {
        return $.ajax({
            url: wcComboPaymentCallbacks.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend({
                action: action,
                nonce: wcComboPaymentCallbacks.nonce
            }, data || {})
        });
    }

    function setLoading(isLoading) {
        $('#wc-combo-pcb-table-wrap, .wc-combo-pcb-table-wrap').toggleClass('wc-combo-pcb-loading', isLoading);
    }

    function formatAmount(value) {
        if (!value && value !== 0) {
            return '-';
        }
        return wcComboPaymentCallbacks.currencySymbol + parseFloat(value).toFixed(2);
    }

    function renderStats(stats) {
        $('[data-stat="total_callbacks"]').text(stats.total_callbacks || 0);
        $('[data-stat="total_orders"]').text(stats.total_orders || 0);
        $('[data-stat="total_amount"]').text(formatAmount(stats.total_amount || 0));
    }

    function renderTableHead(columns) {
        var html = '';
        html += '<th>ID</th>';
        html += '<th>Date</th>';
        html += '<th>Order</th>';
        html += '<th>Amount</th>';
        html += '<th>Status</th>';

        columns.forEach(function (column) {
            html += '<th>' + escapeHtml(column) + '</th>';
        });

        html += '<th>Actions</th>';
        $('#wc-combo-pcb-table-head').html(html);
    }

    function renderTableBody(rows, columns) {
        if (!rows.length) {
            var colspan = 6 + columns.length;
            $('#wc-combo-pcb-table-body').html(
                '<tr><td colspan="' + colspan + '">' + escapeHtml(wcComboPaymentCallbacks.i18n.noData) + '</td></tr>'
            );
            return;
        }

        var html = '';

        rows.forEach(function (row) {
            html += '<tr data-log-id="' + row.id + '">';
            html += '<td>#' + row.id + '</td>';
            html += '<td>' + escapeHtml(row.created_at) + '</td>';
            html += '<td>' + (row.order_link
                ? '<a href="' + row.order_link + '">#' + row.order_id + '</a>'
                : (row.order_id ? '#' + row.order_id : '-')) + '</td>';
            html += '<td><strong>' + (row.amount_formatted || formatAmount(row.amount)) + '</strong></td>';
            html += '<td>' + escapeHtml(row.payment_status || '-') + '</td>';

            columns.forEach(function (column) {
                var value = row.fields && row.fields[column] ? row.fields[column] : '';
                html += '<td class="field-cell" title="' + escapeAttr(value) + '">' + escapeHtml(value) + '</td>';
            });

            html += '<td class="actions-cell">';
            html += '<button type="button" class="button button-small wc-combo-verify-payment" data-log-id="' + row.id + '">';
            html += escapeHtml(wcComboPaymentCallbacks.i18n.verify);
            html += '</button>';

            if (row.can_import_ipay) {
                html += ' <button type="button" class="button button-small wc-combo-import-ipay-url" data-order-id="' + row.order_id + '">';
                html += escapeHtml(wcComboPaymentCallbacks.i18n.importUrl);
                html += '</button>';
            }

            if (row.verify_response) {
                try {
                    var verify = JSON.parse(row.verify_response);
                    if (verify.gateway_status_label) {
                        html += '<span class="verify-result">' + escapeHtml(verify.gateway_status_label) + '</span>';
                    }
                } catch (e) {
                    // ignore invalid JSON
                }
            }

            html += '</td>';
            html += '</tr>';
        });

        $('#wc-combo-pcb-table-body').html(html);
    }

    function renderPagination() {
        var totalPages = Math.max(1, Math.ceil(state.total / state.perPage));
        var start = state.total === 0 ? 0 : ((state.page - 1) * state.perPage) + 1;
        var end = Math.min(state.page * state.perPage, state.total);

        var html = '<div class="page-info">';
        html += 'Showing ' + start + '-' + end + ' of ' + state.total;
        html += '</div><div class="page-buttons">';

        html += '<button type="button" class="button" id="wc-combo-pcb-prev" ' + (state.page <= 1 ? 'disabled' : '') + '>Previous</button> ';
        html += '<span> Page ' + state.page + ' / ' + totalPages + ' </span> ';
        html += '<button type="button" class="button" id="wc-combo-pcb-next" ' + (state.page >= totalPages ? 'disabled' : '') + '>Next</button>';

        html += '</div>';
        $('#wc-combo-pcb-pagination').html(html);
    }

    function loadStats() {
        return post('wc_combo_payment_callbacks_stats', $.extend({
            gateway_id: state.gateway
        }, getDateRangePayload())).done(function (response) {
            if (response.success) {
                renderStats(response.data);
            }
        });
    }

    function loadTable() {
        setLoading(true);

        return post('wc_combo_payment_callbacks_data', $.extend({
            gateway_id: state.gateway,
            search: state.search,
            page: state.page,
            per_page: state.perPage
        }, getDateRangePayload())).done(function (response) {
            if (!response.success) {
                $('#wc-combo-pcb-table-body').html(
                    '<tr><td colspan="6">' + escapeHtml(wcComboPaymentCallbacks.i18n.error) + '</td></tr>'
                );
                return;
            }

            state.columns = response.data.columns || [];
            state.total = response.data.total || 0;

            renderTableHead(state.columns);
            renderTableBody(response.data.rows || [], state.columns);
            renderPagination();
        }).fail(function () {
            $('#wc-combo-pcb-table-body').html(
                '<tr><td colspan="6">' + escapeHtml(wcComboPaymentCallbacks.i18n.error) + '</td></tr>'
            );
        }).always(function () {
            setLoading(false);
        });
    }

    function reloadAll() {
        $.when(loadStats(), loadTable());
    }

    function escapeHtml(value) {
        return $('<div/>').text(value || '').html();
    }

    function escapeAttr(value) {
        return String(value || '').replace(/"/g, '&quot;');
    }

    function updateBulkProgress(processed, total) {
        var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        $('#wc-combo-pcb-bulk-progress').prop('hidden', false);
        $('#wc-combo-pcb-bulk-progress-fill').css('width', percent + '%');
        $('#wc-combo-pcb-bulk-progress-text').text(
            wcComboPaymentCallbacks.i18n.bulkProgress
                .replace('%1$d', processed)
                .replace('%2$d', total)
        );
    }

    function appendBulkResults(results) {
        var $list = $('#wc-combo-pcb-bulk-results');
        results.forEach(function (item) {
            var cssClass = 'is-error';
            if (item.skipped) {
                cssClass = 'is-skipped';
            } else if (item.success) {
                cssClass = 'is-success';
            }
            $list.append(
                '<li class="' + cssClass + '">#' + item.order_id + ': ' + escapeHtml(item.message) + '</li>'
            );
        });
    }

    function processBulkBatch() {
        if (bulkState.index >= bulkState.orderIds.length) {
            state.bulkRunning = false;
            $('#wc-combo-pcb-bulk-verify').prop('disabled', false).text(wcComboPaymentCallbacks.i18n.bulkVerify);
            $('#wc-combo-pcb-bulk-progress-text').text(wcComboPaymentCallbacks.i18n.bulkComplete);

            state.gateway = bulkState.gateway;
            state.page = 1;
            setDateInputs(bulkState.startDate, bulkState.endDate);

            $('.wc-combo-pcb-tabs .nav-tab').removeClass('nav-tab-active');
            $('.wc-combo-pcb-tabs .nav-tab[data-gateway="' + bulkState.gateway + '"]').addClass('nav-tab-active');

            reloadAll();
            return;
        }

        var batch = bulkState.orderIds.slice(bulkState.index, bulkState.index + bulkState.batchSize);

        post('wc_combo_bulk_verify_batch', {
            order_ids: batch,
            gateway_id: bulkState.gateway,
            start_date: bulkState.startDate,
            end_date: bulkState.endDate
        }).done(function (response) {
            if (!response.success) {
                appendBulkResults([{ order_id: '-', success: false, message: wcComboPaymentCallbacks.i18n.error }]);
                bulkState.index += batch.length;
            } else {
                appendBulkResults(response.data.results || []);
                bulkState.index += response.data.processed || batch.length;
            }
            updateBulkProgress(bulkState.index, bulkState.orderIds.length);
        }).fail(function () {
            appendBulkResults([{ order_id: '-', success: false, message: wcComboPaymentCallbacks.i18n.error }]);
            bulkState.index += batch.length;
            updateBulkProgress(bulkState.index, bulkState.orderIds.length);
        }).always(function () {
            processBulkBatch();
        });
    }

    function startBulkVerify() {
        if (state.bulkRunning) {
            return;
        }

        var gateway = $('#wc-combo-pcb-bulk-gateway').val();
        var period = $('#wc-combo-pcb-bulk-month').val();

        if (!gateway) {
            window.alert(wcComboPaymentCallbacks.i18n.selectGateway);
            return;
        }

        state.bulkRunning = true;
        bulkState = {
            orderIds: [],
            index: 0,
            batchSize: 5,
            startDate: '',
            endDate: '',
            gateway: gateway
        };

        $('#wc-combo-pcb-bulk-results').empty();
        $('#wc-combo-pcb-bulk-verify').prop('disabled', true).text(wcComboPaymentCallbacks.i18n.bulkVerifying);
        updateBulkProgress(0, 0);

        post('wc_combo_bulk_verify_prepare', {
            gateway_id: gateway,
            period: period
        }).done(function (response) {
            if (!response.success) {
                state.bulkRunning = false;
                $('#wc-combo-pcb-bulk-verify').prop('disabled', false).text(wcComboPaymentCallbacks.i18n.bulkVerify);
                window.alert((response.data && response.data.message) || wcComboPaymentCallbacks.i18n.error);
                return;
            }

            bulkState.orderIds = response.data.order_ids || [];
            bulkState.startDate = response.data.start_date || '';
            bulkState.endDate = response.data.end_date || '';

            if (response.data.skipped > 0) {
                $('#wc-combo-pcb-bulk-results').append(
                    '<li class="is-skipped">' + escapeHtml(
                        wcComboPaymentCallbacks.i18n.bulkSkippedSummary.replace('%d', response.data.skipped)
                    ) + '</li>'
                );
            }

            if (!bulkState.orderIds.length) {
                state.bulkRunning = false;
                $('#wc-combo-pcb-bulk-verify').prop('disabled', false).text(wcComboPaymentCallbacks.i18n.bulkVerify);
                $('#wc-combo-pcb-bulk-progress-text').text(
                    response.data.skipped > 0
                        ? wcComboPaymentCallbacks.i18n.bulkAllSkipped
                        : wcComboPaymentCallbacks.i18n.bulkNoOrders
                );
                return;
            }

            updateBulkProgress(0, bulkState.orderIds.length);
            processBulkBatch();
        }).fail(function () {
            state.bulkRunning = false;
            $('#wc-combo-pcb-bulk-verify').prop('disabled', false).text(wcComboPaymentCallbacks.i18n.bulkVerify);
            window.alert(wcComboPaymentCallbacks.i18n.error);
        });
    }

    function importIpayUrl(orderId, $button) {
        var url = window.prompt(wcComboPaymentCallbacks.i18n.importUrlPrompt, '');
        if (!url) {
            return;
        }

        var originalText = $button.text();
        $button.prop('disabled', true);

        post('wc_combo_import_ipay_callback', {
            order_id: orderId,
            callback_url_raw: url
        }).done(function (response) {
            if (response.success) {
                window.alert(response.data.message || wcComboPaymentCallbacks.i18n.importSuccess);
                reloadAll();
            } else {
                var message = response.data && response.data.message ? response.data.message : wcComboPaymentCallbacks.i18n.error;
                window.alert(message);
            }
        }).fail(function () {
            window.alert(wcComboPaymentCallbacks.i18n.error);
        }).always(function () {
            $button.prop('disabled', false).text(originalText);
        });
    }

    function verifyPayment(logId, $button) {
        var originalText = $button.text();
        $button.prop('disabled', true).text(wcComboPaymentCallbacks.i18n.verifying);

        post('wc_combo_payment_callbacks_verify', {
            log_id: logId
        }).done(function (response) {
            var $row = $button.closest('tr');
            $row.find('.verify-result').remove();

            if (response.success) {
                var label = response.data && response.data.data && response.data.data.gateway_status_label
                    ? response.data.data.gateway_status_label
                    : response.data.message;
                $button.after('<span class="verify-result">' + escapeHtml(label) + '</span>');
            } else {
                var message = response.data && response.data.message ? response.data.message : wcComboPaymentCallbacks.i18n.error;
                $button.after('<span class="verify-result is-error">' + escapeHtml(message) + '</span>');
            }
        }).fail(function () {
            $button.after('<span class="verify-result is-error">' + escapeHtml(wcComboPaymentCallbacks.i18n.error) + '</span>');
        }).always(function () {
            $button.prop('disabled', false).text(originalText);
        });
    }

    $(function () {
        if (!$('#wc-combo-pcb-table').length) {
            return;
        }

        state.startDate = wcComboPaymentCallbacks.defaultStartDate;
        state.endDate = wcComboPaymentCallbacks.defaultEndDate;
        readDateInputs();

        if ($('#wc-combo-pcb-bulk-gateway').val() === '' && state.gateway !== 'all') {
            $('#wc-combo-pcb-bulk-gateway').val(state.gateway);
        }

        reloadAll();

        $('.wc-combo-pcb-tabs .nav-tab').on('click', function (event) {
            event.preventDefault();
            $('.wc-combo-pcb-tabs .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            state.gateway = $(this).data('gateway');
            state.page = 1;
            if (state.gateway !== 'all') {
                $('#wc-combo-pcb-bulk-gateway').val(state.gateway);
            }
            reloadAll();
        });

        $('#wc-combo-pcb-start-date, #wc-combo-pcb-end-date').on('change', function () {
            readDateInputs();
            state.page = 1;
            reloadAll();
        });

        $('#wc-combo-pcb-refresh').on('click', function () {
            readDateInputs();
            state.page = 1;
            reloadAll();
        });

        var searchTimer;
        $('#wc-combo-pcb-search').on('input', function () {
            clearTimeout(searchTimer);
            var value = $(this).val();
            searchTimer = setTimeout(function () {
                state.search = value;
                state.page = 1;
                loadTable();
            }, 350);
        });

        $(document).on('click', '#wc-combo-pcb-prev', function () {
            if (state.page > 1) {
                state.page -= 1;
                loadTable();
            }
        });

        $(document).on('click', '#wc-combo-pcb-next', function () {
            var totalPages = Math.max(1, Math.ceil(state.total / state.perPage));
            if (state.page < totalPages) {
                state.page += 1;
                loadTable();
            }
        });

        $(document).on('click', '.wc-combo-verify-payment', function () {
            verifyPayment($(this).data('log-id'), $(this));
        });

        $(document).on('click', '.wc-combo-import-ipay-url', function () {
            importIpayUrl($(this).data('order-id'), $(this));
        });

        $('#wc-combo-pcb-bulk-verify').on('click', function () {
            startBulkVerify();
        });
    });
}(jQuery));
