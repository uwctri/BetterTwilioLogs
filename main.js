/**
 * Twilio Logs Dashboard JavaScript
 */
(function(window, document, $) {
    'use strict';

    window.TwilioLogs = {
        activeStatusFilter: 'all',
        activeTypeFilter: 'all',
        searchTerm: '',

        getEmModule: function() {
            if (typeof ExternalModules !== 'undefined' && ExternalModules.UWMadison) {
                return ExternalModules.UWMadison.BetterTwilioLogs || ExternalModules.UWMadison.TwilioLogs || null;
            }
            return null;
        },

        init: function() {
            var self = this;

            // Search input binding
            $('#twilioSearchInput').on('keyup input', function() {
                self.searchTerm = $(this).val().toLowerCase().trim();
                self.applyFilters();
            });

            // Filter button bindings
            $('.filter-status-btn').on('click', function(e) {
                e.preventDefault();
                $('.filter-status-btn').removeClass('active');
                $(this).addClass('active');
                self.activeStatusFilter = $(this).data('status');
                self.applyFilters();
            });

            $('.filter-type-btn').on('click', function(e) {
                e.preventDefault();
                $('.filter-type-btn').removeClass('active');
                $(this).addClass('active');
                self.activeTypeFilter = $(this).data('type');
                self.applyFilters();
            });

            // Sync button
            $('#btnSyncTwilio').on('click', function() {
                self.syncNow();
            });

            // PHI acknowledge button
            $('#btnAckPhi').on('click', function() {
                self.acknowledgePhi();
            });

            // Initial filter count calculations
            self.updateMetrics();
        },

        showToast: function(message, type) {
            var bgClass = (type === 'error') ? 'bg-danger text-white' : 'bg-success text-white';
            var icon = (type === 'error') ? 'fa-exclamation-triangle' : 'fa-check-circle';
            
            var toastHtml = '' +
                '<div class="toast align-items-center ' + bgClass + ' border-0 show shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">' +
                '  <div class="d-flex">' +
                '    <div class="toast-body d-flex align-items-center">' +
                '      <i class="fas ' + icon + ' me-2 fs-5"></i> ' +
                '      <span>' + message + '</span>' +
                '    </div>' +
                '    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
                '  </div>' +
                '</div>';

            var $toastContainer = $('#twilioToastContainer');
            if ($toastContainer.length === 0) {
                $toastContainer = $('<div id="twilioToastContainer" class="twilio-toast"></div>').appendTo('body');
            }

            var $toast = $(toastHtml).appendTo($toastContainer);
            setTimeout(function() {
                $toast.fadeOut(400, function() { $(this).remove(); });
            }, 4500);
        },

        acknowledgePhi: function() {
            var self = this;
            var $btn = $('#btnAckPhi');
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Acknowledging...');

            var em = self.getEmModule();
            if (em) {
                em.ajax('acknowledgePhiWarning', {})
                    .then(function(res) {
                        $('#phiWarningModal').fadeOut(300, function() { $(this).remove(); });
                        self.showToast('PHI acknowledgment recorded.', 'success');
                    })
                    .catch(function(err) {
                        $btn.prop('disabled', false).text('I Understand and Acknowledge');
                        alert('Could not record acknowledgment: ' + (err.message || err));
                    });
            } else {
                $('#phiWarningModal').fadeOut(300);
            }
        },

        syncNow: function() {
            var self = this;
            var $btn = $('#btnSyncTwilio');
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-sync fa-spin me-1"></i> Syncing...');

            var em = self.getEmModule();
            if (em) {
                em.ajax('syncNow', {})
                    .then(function(res) {
                        $btn.prop('disabled', false).html(originalHtml);
                        if (res && res.success) {
                            var msg = 'Sync complete! Fetched ' + (res.inbound_count || 0) + ' inbound and ' + (res.outbound_count || 0) + ' outbound log(s).';
                            self.showToast(msg, 'success');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1200);
                        } else {
                            self.showToast('Sync failed: ' + (res.message || 'Unknown error'), 'error');
                        }
                    })
                    .catch(function(err) {
                        $btn.prop('disabled', false).html(originalHtml);
                        self.showToast('Error syncing with Twilio: ' + (err.message || err), 'error');
                    });
            } else {
                $btn.prop('disabled', false).html(originalHtml);
                self.showToast('Module AJAX endpoint unavailable.', 'error');
            }
        },

        toggleStatus: function(logId, targetStatus) {
            var self = this;
            var $row = $('tr[data-log-id="' + logId + '"]');
            var currentNotes = $row.attr('data-notes') || '';

            var em = self.getEmModule();
            if (em) {
                em.ajax('updateTaskStatus', {
                    log_id: logId,
                    status: targetStatus,
                    notes: currentNotes
                }).then(function(res) {
                    if (res && res.success) {
                        self.updateRowStatusUI(logId, targetStatus, res.task);
                        self.showToast('Task marked as ' + targetStatus + '.', 'success');
                    } else {
                        self.showToast('Failed to update task: ' + (res.message || ''), 'error');
                    }
                }).catch(function(err) {
                    self.showToast('Error updating status: ' + (err.message || err), 'error');
                });
            }
        },

        updateRowStatusUI: function(logId, status, task) {
            var $row = $('tr[data-log-id="' + logId + '"]');
            $row.attr('data-status', status);

            var $badgeContainer = $row.find('.status-badge-cell');
            var $actionBtn = $row.find('.btn-toggle-status');

            if (status === 'resolved') {
                $badgeContainer.html('<span class="badge rounded-pill badge-task-resolved"><i class="fas fa-check-circle me-1"></i>Resolved</span>');
                $actionBtn.removeClass('btn-outline-success')
                          .addClass('btn-outline-secondary')
                          .html('<i class="fas fa-undo me-1"></i>Reopen')
                          .attr('onclick', "TwilioLogs.toggleStatus('" + logId + "', 'open')");
            } else {
                $badgeContainer.html('<span class="badge rounded-pill badge-task-open"><i class="fas fa-exclamation-circle me-1"></i>Open</span>');
                $actionBtn.removeClass('btn-outline-secondary')
                          .addClass('btn-outline-success')
                          .html('<i class="fas fa-check me-1"></i>Resolve')
                          .attr('onclick', "TwilioLogs.toggleStatus('" + logId + "', 'resolved')");
            }

            if (task && task.updated_by) {
                var auditText = 'Updated by ' + task.updated_by + ' on ' + task.updated_at;
                $row.find('.task-audit-trail').text(auditText);
            }

            this.updateMetrics();
            this.applyFilters();
        },

        openNotesModal: function(logId) {
            var $row = $('tr[data-log-id="' + logId + '"]');
            var notes = $row.attr('data-notes') || '';
            var status = $row.attr('data-status') || 'open';

            $('#modalLogId').val(logId);
            $('#modalTaskStatus').val(status);
            $('#modalTaskNotes').val(notes);

            var modalElem = document.getElementById('taskNotesModal');
            if (window.bootstrap && window.bootstrap.Modal) {
                var modal = bootstrap.Modal.getOrCreateInstance(modalElem);
                modal.show();
            } else {
                $('#taskNotesModal').modal('show');
            }
        },

        saveNotes: function() {
            var self = this;
            var logId = $('#modalLogId').val();
            var status = $('#modalTaskStatus').val();
            var notes = $('#modalTaskNotes').val();

            var em = self.getEmModule();
            if (em) {
                em.ajax('updateTaskStatus', {
                    log_id: logId,
                    status: status,
                    notes: notes
                }).then(function(res) {
                    if (res && res.success) {
                        var $row = $('tr[data-log-id="' + logId + '"]');
                        $row.attr('data-notes', notes);
                        $row.find('.notes-preview').text(notes ? notes : 'No notes added');
                        self.updateRowStatusUI(logId, status, res.task);

                        var modalElem = document.getElementById('taskNotesModal');
                        if (window.bootstrap && window.bootstrap.Modal) {
                            var modal = bootstrap.Modal.getInstance(modalElem);
                            if (modal) modal.hide();
                        } else {
                            $('#taskNotesModal').modal('hide');
                        }

                        self.showToast('Notes and status saved.', 'success');
                    } else {
                        self.showToast('Failed to save notes: ' + (res.message || ''), 'error');
                    }
                }).catch(function(err) {
                    self.showToast('Error saving notes: ' + (err.message || err), 'error');
                });
            }
        },

        copyToClipboard: function(text, btnElem) {
            var self = this;
            navigator.clipboard.writeText(text).then(function() {
                var $btn = $(btnElem);
                var originalHtml = $btn.html();
                $btn.html('<i class="fas fa-check text-success"></i>');
                setTimeout(function() {
                    $btn.html(originalHtml);
                }, 1500);
            }).catch(function() {
                self.showToast('Could not copy to clipboard', 'error');
            });
        },

        applyFilters: function() {
            var self = this;
            var visibleCount = 0;

            $('#twilioLogsTable tbody tr.log-row').each(function() {
                var $row = $(this);
                var rowStatus = $row.attr('data-status') || 'open';
                var rowType = $row.attr('data-type') || 'inbound';
                var searchableText = $row.attr('data-searchable') || '';

                var statusMatch = (self.activeStatusFilter === 'all') || (rowStatus === self.activeStatusFilter);
                var typeMatch = (self.activeTypeFilter === 'all') || (rowType === self.activeTypeFilter);
                var textMatch = (self.searchTerm === '') || (searchableText.indexOf(self.searchTerm) !== -1);

                if (statusMatch && typeMatch && textMatch) {
                    $row.show();
                    visibleCount++;
                } else {
                    $row.hide();
                }
            });

            if (visibleCount === 0) {
                $('#noLogsRow').show();
            } else {
                $('#noLogsRow').hide();
            }

            $('#visibleCountBadge').text(visibleCount);
        },

        updateMetrics: function() {
            var openCount = 0;
            var stopCount = 0;
            var failedCount = 0;
            var inboundCount = 0;
            var totalCount = 0;

            $('#twilioLogsTable tbody tr.log-row').each(function() {
                var $row = $(this);
                totalCount++;
                var status = $row.attr('data-status');
                var type = $row.attr('data-type');

                if (status === 'open') {
                    openCount++;
                }
                if (type === 'stop') {
                    stopCount++;
                } else if (type === 'failed') {
                    failedCount++;
                } else if (type === 'inbound') {
                    inboundCount++;
                }
            });

            $('#metricOpenTasks').text(openCount);
            $('#metricStopCount').text(stopCount);
            $('#metricFailedCount').text(failedCount);
            $('#metricInboundCount').text(inboundCount);
            $('#metricTotalCount').text(totalCount);
        }
    };

    $(document).ready(function() {
        window.BetterTwilioLogs = window.TwilioLogs;
        window.BetterTwilioLogs.init();
    });

})(window, document, jQuery);
