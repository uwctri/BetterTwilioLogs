(() => {
    const em = ExternalModules.UWMadison.BetterTwilioLogs;
    const currentUsername = em.currentUsername;

    let activeStatusFilter = 'all';
    let activeTypeFilter = 'all';
    let activeMatchFilter = 'all';
    let searchTerm = '';
    let currentSort = { column: null, direction: 'asc' };
    let currentPage = 1;
    let perPage = 20;

    const showToast = (message, type) => {
        const bgClass = (type === 'error') ? 'bg-danger text-white' : 'bg-success text-white';
        const icon = (type === 'error') ? 'fa-exclamation-triangle' : 'fa-check-circle';

        const toastHtml = `
            <div class="toast align-items-center ${bgClass} border-0 show shadow-lg mb-2" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="d-flex align-items-center justify-content-between">
                <div class="toast-body d-flex align-items-center">
                  <i class="fas ${icon} me-2 fs-6"></i>
                  <span>${message}</span>
                </div>
                <button type="button" class="toast-close-btn" aria-label="Close">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>`;

        let $toastContainer = $('#twilioToastContainer');
        if ($toastContainer.length === 0)
            $toastContainer = $('<div id="twilioToastContainer" class="twilio-toast"></div>').appendTo('body');

        const maxToasts = 2;
        const $existing = $toastContainer.children('.toast');
        if ($existing.length >= maxToasts)
            $existing.slice(0, $existing.length - maxToasts + 1).each((_, el) => {
                $(el).stop(true, true).fadeOut(200, () => $(el).remove());
            });

        const $toast = $(toastHtml).appendTo($toastContainer);

        $toast.find('.toast-close-btn').on('click', () => {
            $toast.stop(true, true).fadeOut(200, () => $toast.remove());
        });

        setTimeout(() => {
            $toast.fadeOut(400, () => $toast.remove());
        }, 3500);
    };

    const acknowledgePhi = () => {
        const $btn = $('#btnAckPhi');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Acknowledging...');

        em.ajax('acknowledgePhiWarning', {})
            .then(() => {
                $('#phiWarningModal').fadeOut(300, () => $('#phiWarningModal').remove());
                showToast('PHI acknowledgment recorded.', 'success');
            })
            .catch((err) => {
                $btn.prop('disabled', false).text('I Understand and Acknowledge');
                alert('Could not record acknowledgment: ' + (err.message || err));
            });
    };

    const syncNow = () => {
        const $btn = $('#btnSyncTwilio');
        const originalHtml = '<i class="fas fa-sync-alt me-1"></i> Sync Now';

        const btnWidth = $btn.outerWidth();
        if (btnWidth > 0)
            $btn.css('width', btnWidth + 'px');
        $btn.prop('disabled', true);

        let totalInbound = 0;
        let totalOutbound = 0;
        let totalLogged = 0;

        const step = (phase, nextUrl) => {
            $btn.html('<i class="fas fa-sync fa-spin me-1"></i> Syncing');

            em.ajax('syncNow', {
                phase: phase,
                next_page_url: nextUrl
            }).then((res) => {
                if (!res || !res.success) {
                    $btn.prop('disabled', false).html(originalHtml).css('width', '');
                    showToast('Sync error: ' + (res ? res.message : 'Unknown error'), 'error');
                    return;
                }

                const batchCount = res.batch_logged || 0;
                totalLogged += batchCount;
                if (res.phase === 'inbound')
                    totalInbound += batchCount;
                else
                    totalOutbound += batchCount;

                if (res.has_more) {
                    step(res.phase, res.next_page_url || null);
                    return;
                }

                $btn.prop('disabled', false).html(originalHtml).css('width', '');
                let summaryMsg = `Sync complete! ${totalLogged} new item(s) logged (${totalInbound} inbound, ${totalOutbound} outbound).`;
                if (totalLogged === 0)
                    summaryMsg = 'Sync complete! All messages in lookback window are up to date.';
                showToast(summaryMsg, 'success');
                if (res.last_fetch)
                    $('#lastSyncBadge').text(res.last_fetch);
                setTimeout(() => window.location.reload(), 1200);
            }).catch((err) => {
                $btn.prop('disabled', false).html(originalHtml).css('width', '');
                showToast('Error syncing with Twilio: ' + (err.message || err), 'error');
            });
        };

        step('inbound', null);
    };

    const updateMetrics = () => {
        let openCount = 0;
        let stopCount = 0;
        let failedCount = 0;
        let inboundCount = 0;
        let totalCount = 0;

        $('#twilioLogsTable tbody tr.log-row').each((_, el) => {
            const $row = $(el);
            totalCount++;
            const status = $row.attr('data-status');
            const type = $row.attr('data-type');

            if (status === 'open') openCount++;
            if (type === 'stop') stopCount++;
            else if (type === 'failed') failedCount++;
            else if (type === 'inbound') inboundCount++;
        });

        $('#metricOpenTasks').text(openCount);
        $('#metricStopCount').text(stopCount);
        $('#metricFailedCount').text(failedCount);
        $('#metricInboundCount').text(inboundCount);
        $('#metricTotalCount').text(totalCount);
    };

    const renderPagination = (totalItems) => {
        const totalPages = Math.max(1, Math.ceil(totalItems / perPage));
        const $nav = $('#paginationNav');
        const $list = $('#paginationList');
        const $info = $('#paginationInfo');

        if (totalItems === 0) {
            $info.text('Showing 0 of 0 entries');
            $list.empty();
            $nav.hide();
            return;
        }

        $nav.show();
        const start = (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, totalItems);
        $info.text(`Showing ${start} to ${end} of ${totalItems} entries`);

        $list.empty();

        const prevDisabled = (currentPage === 1) ? ' disabled' : '';
        const $prev = $(`<li class="page-item${prevDisabled}"><a class="page-link" href="#" aria-label="Previous">&laquo;</a></li>`);
        $prev.find('a').on('click', (e) => {
            e.preventDefault();
            if (currentPage > 1) {
                currentPage--;
                applyFilters();
            }
        });
        $list.append($prev);

        const pages = [];
        if (totalPages <= 7)
            for (let i = 1; i <= totalPages; i++)
                pages.push(i);
        else {
            pages.push(1);
            if (currentPage > 3)
                pages.push('...');
            const startPage = Math.max(2, currentPage - 1);
            const endPage = Math.min(totalPages - 1, currentPage + 1);
            for (let p = startPage; p <= endPage; p++)
                pages.push(p);
            if (currentPage < totalPages - 2)
                pages.push('...');
            pages.push(totalPages);
        }

        $.each(pages, (_, p) => {
            if (p === '...') {
                $list.append('<li class="page-item disabled"><span class="page-link">&hellip;</span></li>');
                return;
            }

            const activeClass = (p === currentPage) ? ' active' : '';
            const $li = $(`<li class="page-item${activeClass}"><a class="page-link" href="#">${p}</a></li>`);
            $li.find('a').on('click', (e) => {
                e.preventDefault();
                if (currentPage !== p) {
                    currentPage = p;
                    applyFilters();
                }
            });
            $list.append($li);
        });

        const nextDisabled = (currentPage === totalPages) ? ' disabled' : '';
        const $next = $(`<li class="page-item${nextDisabled}"><a class="page-link" href="#" aria-label="Next">&raquo;</a></li>`);
        $next.find('a').on('click', (e) => {
            e.preventDefault();
            if (currentPage < totalPages) {
                currentPage++;
                applyFilters();
            }
        });
        $list.append($next);
    };

    const applyFilters = () => {
        const matchedRows = [];

        $('#twilioLogsTable tbody tr.log-row').each((_, el) => {
            const $row = $(el);
            const rowStatus = $row.attr('data-status') || 'open';
            const rowType = $row.attr('data-type') || 'inbound';
            const rowRecord = ($row.attr('data-record') || '').trim();
            const searchableText = $row.attr('data-searchable') || '';

            const statusMatch = (activeStatusFilter === 'all') || (rowStatus === activeStatusFilter);
            const typeMatch = (activeTypeFilter === 'all') || (rowType === activeTypeFilter);
            const matchMatch = (activeMatchFilter === 'all') || (activeMatchFilter === 'matched' && rowRecord !== '');
            const textMatch = (searchTerm === '') || (searchableText.indexOf(searchTerm) !== -1);

            if (statusMatch && typeMatch && matchMatch && textMatch)
                matchedRows.push($row);
            else
                $row.hide();
        });

        const totalMatched = matchedRows.length;
        const totalPages = Math.max(1, Math.ceil(totalMatched / perPage));
        if (currentPage > totalPages)
            currentPage = totalPages;
        if (currentPage < 1)
            currentPage = 1;

        const startIndex = (currentPage - 1) * perPage;
        const endIndex = startIndex + perPage;

        $.each(matchedRows, (idx, $row) => {
            if (idx >= startIndex && idx < endIndex)
                $row.show();
            else
                $row.hide();
        });

        if (totalMatched > 0) {
            $('#noLogsRow').hide();
            $('#initialEmptyRow').hide();
        } else if ($('#initialEmptyRow').length) {
            $('#initialEmptyRow').css('display', 'table-row');
            $('#noLogsRow').hide();
        } else
            $('#noLogsRow').css('display', 'table-row');

        $('#visibleCountBadge').text(totalMatched);
        renderPagination(totalMatched);
    };

    const sortTable = (column) => {
        if (currentSort.column === column)
            currentSort.direction = (currentSort.direction === 'asc') ? 'desc' : 'asc';
        else {
            currentSort.column = column;
            currentSort.direction = (column === 'timestamp') ? 'desc' : 'asc';
        }

        $('.sortable-header').removeClass('sort-active')
            .find('.sort-icon')
            .removeClass('fa-sort-up fa-sort-down')
            .addClass('fa-sort');

        const $th = $(`.sortable-header[data-sort="${column}"]`);
        $th.addClass('sort-active');
        const iconClass = (currentSort.direction === 'asc') ? 'fa-sort-up' : 'fa-sort-down';
        $th.find('.sort-icon').removeClass('fa-sort').addClass(iconClass);

        const $rows = $('#twilioLogsTable tbody tr.log-row').get();
        const dir = (currentSort.direction === 'asc') ? 1 : -1;

        $rows.sort((a, b) => {
            const $a = $(a);
            const $b = $(b);

            if (column === 'timestamp') {
                const valA = parseInt($a.attr('data-timestamp') || '0', 10);
                const valB = parseInt($b.attr('data-timestamp') || '0', 10);
                return (valA - valB) * dir;
            }

            if (column === 'record') {
                const valA = $a.attr('data-record') || '';
                const valB = $b.attr('data-record') || '';
                if (valA === '' && valB !== '') return 1;
                if (valA !== '' && valB === '') return -1;
                return valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' }) * dir;
            }

            const valA = ($a.attr('data-' + column) || '').toLowerCase();
            const valB = ($b.attr('data-' + column) || '').toLowerCase();
            return valA.localeCompare(valB) * dir;
        });

        const $tbody = $('#twilioLogsTable tbody');
        $.each($rows, (_, row) => $tbody.append(row));

        if ($('#noLogsRow').length)
            $tbody.append($('#noLogsRow'));
        if ($('#initialEmptyRow').length)
            $tbody.append($('#initialEmptyRow'));

        applyFilters();
    };

    const updateRowStatusUI = (logId, status, task) => {
        const $row = $('tr[data-log-id="' + logId + '"]');
        $row.attr('data-status', status);

        const $badgeContainer = $row.find('.status-badge-cell');
        const $actionBtn = $row.find('.btn-toggle-status');

        if (status === 'resolved') {
            $badgeContainer.html('<span class="badge rounded-pill badge-task-resolved"><i class="fas fa-check-circle me-1"></i>Resolved</span>');
            $actionBtn.removeClass('btn-outline-success')
                .addClass('btn-outline-secondary')
                .html('<i class="fas fa-undo"></i>')
                .attr('title', 'Reopen this task')
                .attr('onclick', `ExternalModules.UWMadison.BetterTwilioLogs.toggleStatus('${logId}', 'open')`);
        } else {
            $badgeContainer.html('<span class="badge rounded-pill badge-task-open"><i class="fas fa-exclamation-circle me-1"></i>Open</span>');
            $actionBtn.removeClass('btn-outline-secondary')
                .addClass('btn-outline-success')
                .html('<i class="fas fa-check"></i>')
                .attr('title', 'Mark task as resolved')
                .attr('onclick', `ExternalModules.UWMadison.BetterTwilioLogs.toggleStatus('${logId}', 'resolved')`);
        }

        if (!task) {
            updateMetrics();
            applyFilters();
            return;
        }

        if (typeof task.notes !== 'undefined') {
            $row.attr('data-notes', task.notes);
            const $notesPreview = $row.find('.notes-preview');
            if (task.notes)
                $notesPreview.text(task.notes).attr('title', task.notes);
            else
                $notesPreview.html('<span class="text-muted opacity-75">No notes</span>').removeAttr('title');
        }

        if (task.updated_by) {
            let displayDate = '';
            if (task.updated_at) {
                const parts = task.updated_at.split(/[- :]/);
                if (parts.length >= 3) {
                    const yy = parts[0].length === 4 ? parts[0].substring(2) : parts[0];
                    displayDate = `${parts[1]}/${parts[2]}/${yy}`;
                } else
                    displayDate = task.updated_at;
            }
            const userEscaped = $('<div>').text(task.updated_by).html();
            const dateEscaped = $('<div>').text(displayDate).html();
            const auditHtml = userEscaped + (displayDate ? ' &bull; ' + dateEscaped : '');

            let $audit = $row.find('.task-audit-trail');
            if ($audit.length === 0) {
                $row.find('.notes-cell').append('<small class="text-muted d-block task-audit-trail" style="font-size: 0.75rem;"></small>');
                $audit = $row.find('.task-audit-trail');
            }
            $audit.html(auditHtml).show();
        }

        updateMetrics();
        applyFilters();
    };

    const toggleStatus = (logId, targetStatus) => {
        const $row = $('tr[data-log-id="' + logId + '"]');
        const currentNotes = $row.attr('data-notes') || '';

        em.ajax('updateTaskStatus', {
            log_id: logId,
            status: targetStatus,
            notes: currentNotes,
            username: currentUsername || ''
        }).then((res) => {
            if (!res || !res.success) {
                showToast('Failed to update task: ' + (res ? res.message : ''), 'error');
                return;
            }
            updateRowStatusUI(logId, targetStatus, res.task);
            showToast('Task marked as ' + targetStatus + '.', 'success');
        }).catch((err) => {
            showToast('Error updating status: ' + (err.message || err), 'error');
        });
    };

    const openNotesModal = (logId) => {
        const $row = $('tr[data-log-id="' + logId + '"]');
        const notes = $row.attr('data-notes') || '';
        const status = $row.attr('data-status') || 'open';

        $('#modalLogId').val(logId);
        $('#modalTaskStatus').val(status);
        $('#modalTaskNotes').val(notes);

        const modalElem = document.getElementById('taskNotesModal');
        if (window.bootstrap && window.bootstrap.Modal)
            bootstrap.Modal.getOrCreateInstance(modalElem).show();
        else
            $('#taskNotesModal').modal('show');
    };

    const saveNotes = () => {
        const logId = $('#modalLogId').val();
        const status = $('#modalTaskStatus').val();
        const notes = $('#modalTaskNotes').val();

        em.ajax('updateTaskStatus', {
            log_id: logId,
            status: status,
            notes: notes,
            username: currentUsername || ''
        }).then((res) => {
            if (!res || !res.success) {
                showToast('Failed to save notes: ' + (res ? res.message : ''), 'error');
                return;
            }

            const $row = $('tr[data-log-id="' + logId + '"]');
            $row.attr('data-notes', notes);
            const $notesPreview = $row.find('.notes-preview');
            if (notes)
                $notesPreview.text(notes).attr('title', notes);
            else
                $notesPreview.html('<span class="text-muted opacity-75">No notes</span>').removeAttr('title');
            updateRowStatusUI(logId, status, res.task);

            const modalElem = document.getElementById('taskNotesModal');
            if (window.bootstrap && window.bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalElem);
                if (modal)
                    modal.hide();
            } else
                $('#taskNotesModal').modal('hide');

            showToast('Notes and status saved.', 'success');
        }).catch((err) => {
            showToast('Error saving notes: ' + (err.message || err), 'error');
        });
    };

    const copyToClipboard = (text, btnElem) => {
        navigator.clipboard.writeText(text).then(() => {
            const $btn = $(btnElem);
            const originalHtml = $btn.html();
            $btn.html('<i class="fas fa-check text-success"></i>');
            setTimeout(() => $btn.html(originalHtml), 1500);
        }).catch(() => {
            showToast('Could not copy to clipboard', 'error');
        });
    };

    const init = () => {
        const initialPerPage = parseInt($('#recordsPerPageInput').val(), 10);
        perPage = (!isNaN(initialPerPage) && initialPerPage > 0) ? initialPerPage : 20;

        $('#recordsPerPageInput').on('change', (e) => {
            const val = parseInt($(e.currentTarget).val(), 10);
            if (!isNaN(val) && val > 0) {
                perPage = val;
                em.ajax('savePerPage', { per_page: val }).catch(err => console.warn(err));
                currentPage = 1;
                applyFilters();
            } else
                $(e.currentTarget).val(perPage);
        }).on('keydown', (e) => {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                $(e.currentTarget).trigger('change');
            }
        });

        $('#twilioSearchInput').on('keyup input', (e) => {
            searchTerm = $(e.currentTarget).val().toLowerCase().trim();
            currentPage = 1;
            applyFilters();
        });

        $('.filter-status-btn').on('click', (e) => {
            e.preventDefault();
            $('.filter-status-btn').removeClass('active');
            $(e.currentTarget).addClass('active');
            activeStatusFilter = $(e.currentTarget).data('status');
            currentPage = 1;
            applyFilters();
        });

        $('.filter-type-btn').on('click', (e) => {
            e.preventDefault();
            $('.filter-type-btn').removeClass('active');
            $(e.currentTarget).addClass('active');
            activeTypeFilter = $(e.currentTarget).data('type');
            currentPage = 1;
            applyFilters();
        });

        $('.filter-match-btn').on('click', (e) => {
            e.preventDefault();
            $('.filter-match-btn').removeClass('active');
            $(e.currentTarget).addClass('active');
            activeMatchFilter = $(e.currentTarget).data('match');
            currentPage = 1;
            applyFilters();
        });

        $('.sortable-header').on('click', (e) => {
            const col = $(e.currentTarget).data('sort');
            if (col)
                sortTable(col);
        });

        $('#btnSyncTwilio').on('click', () => syncNow());
        $('#btnAckPhi').on('click', () => acknowledgePhi());

        updateMetrics();
        applyFilters();
    };

    Object.assign(em, {
        toggleStatus,
        openNotesModal,
        saveNotes,
        copyToClipboard,
        init
    });

    $(() => {
        em.init();
    });
})();
