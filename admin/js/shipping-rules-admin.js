jQuery(document).ready(function($) {
    /**
     * Rules Page Logic
     */

    // Save button state and unsaved-changes confirmation (Shipping Rules page only)
    var $rulesForm = $('#ass-rules-form');
    if ($rulesForm.length) {
        var formIsDirty = false;
        var formSubmitting = false;
        var $saveBtn = $('#ass-save-rules-btn');
        var assTips = (typeof assSavedStatusTips !== 'undefined') ? assSavedStatusTips : { saved: 'Saved', unsaved: 'Unsaved', modified: 'Unsaved changes' };

        function setFormDirty() {
            formIsDirty = true;
            $saveBtn.prop('disabled', false);
        }

        function serializeSectionState($wrapper) {
            var state = {};
            $wrapper.find('input').each(function() {
                var $el = $(this);
                var name = $el.attr('name');
                if (!name) return;
                if ($el.attr('type') === 'checkbox') {
                    if (!state[name]) state[name] = [];
                    if ($el.is(':checked')) state[name].push($el.val());
                } else if (name.indexOf('[]') !== -1) {
                    if (!state[name]) state[name] = [];
                    state[name].push($el.val());
                } else {
                    state[name] = $el.val();
                }
            });
            for (var k in state) {
                if (Array.isArray(state[k])) state[k].sort();
            }
            return JSON.stringify(state);
        }

        function updateSaveStatus($wrapper) {
            if (!$wrapper || !$wrapper.length) return;
            var $status = $wrapper.find('.ass-save-status');
            if (!$status.length) return;
            var original = $wrapper.data('original');
            var current = serializeSectionState($wrapper);
            if (original === undefined) {
                $status.attr('data-status', 'unsaved').attr('data-tip', assTips.unsaved);
                return;
            }
            if (current === original) {
                $status.attr('data-status', 'saved').attr('data-tip', assTips.saved);
            } else {
                $status.attr('data-status', 'modified').attr('data-tip', assTips.modified);
            }
        }

        function snapshotSectionWrappers() {
            $rulesForm.find('.ass-general-settings-wrapper').each(function() {
                var $w = $(this);
                if ($w.find('.ass-save-status').attr('data-status') === 'saved') {
                    $w.data('original', serializeSectionState($w));
                }
            });
            $rulesForm.find('.ass-section-wrapper').each(function() {
                var $w = $(this);
                if ($w.closest('.ass-general-settings-wrapper').length) return;
                if ($w.find('.ass-save-status').attr('data-status') === 'saved') {
                    $w.data('original', serializeSectionState($w));
                }
            });
        }

        snapshotSectionWrappers();
        $rulesForm.on('change input', '.ass-section-wrapper input, .ass-section-wrapper select, .ass-general-settings-wrapper input, .ass-general-settings-wrapper select', function() {
            var $wrapper = $(this).closest('.ass-general-settings-wrapper').length ? $(this).closest('.ass-general-settings-wrapper') : $(this).closest('.ass-section-wrapper');
            updateSaveStatus($wrapper);
        });
        $(document.body).on('ass-section-changed', function(e, $wrapper) {
            if ($wrapper && $wrapper.length) updateSaveStatus($wrapper);
        });

        $rulesForm.on('change input', 'input:not([type="submit"]), select', setFormDirty);
        $(window).on('beforeunload', function(e) {
            if (formIsDirty && !formSubmitting) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
        $rulesForm.on('submit', function() {
            formSubmitting = true;
        });

        $(document).on('click', '.remove-tag', function() {
            var $wrapper = $(this).closest('.ass-general-settings-wrapper').length ? $(this).closest('.ass-general-settings-wrapper') : $(this).closest('.ass-section-wrapper');
            $(this).closest('.ass-tag-pill').remove();
            if ($wrapper.length) $(document.body).trigger('ass-section-changed', [$wrapper]);
            setFormDirty();
        });
        $(document).on('click', '.add-date-row', setFormDirty);
        $(document).on('click', '.remove-date-row', setFormDirty);
        $(document).on('click', '.add-priority-day-row', setFormDirty);
        $(document).on('click', '.remove-priority-day-row', setFormDirty);
        $(document).on('ass-form-dirty', setFormDirty);
    } else {
        $(document).on('click', '.remove-tag', function() {
            $(this).closest('.ass-tag-pill').remove();
        });
    }

    // Toggle between ASAP and BY DATE panes
    $(document).on('change', '.ass-type-toggle', function() {
        var $card = $(this).closest('.ass-method-card');
        var type = $(this).val();
        $card.find('.ass-pane').addClass('hidden');
        $card.find('.ass-pane-' + type).removeClass('hidden');
    });

    // Initialize Sortable for tag source and dropzones
    function initSortable() {
        // Source list (copying instead of moving)
        var sourceList = $('.ass-tag-source')[0];
        if (sourceList && !$(sourceList).data('sortable-initialized')) {
            new Sortable(sourceList, {
                group: {
                    name: 'tags',
                    pull: 'clone',
                    put: false
                },
                sort: false,
                animation: 150
            });
            $(sourceList).data('sortable-initialized', true);
        }

        // Dropzones
        $('.ass-tag-dropzone').each(function() {
            var $dropzone = $(this);
            if ($dropzone.data('sortable-initialized')) return;

            new Sortable(this, {
                group: 'tags',
                animation: 150,
                onAdd: function(evt) {
                    var itemEl = evt.item;
                    var tagId = $(itemEl).data('id');
                    var tagName = $(itemEl).text().trim();
                    var type = $dropzone.data('type');
                    var methodId = $dropzone.data('method-id');
                    var $row = $dropzone.closest('.ass-date-row');
                    var dateIndex = $row.length ? $row.data('index') : null;

                    // Check if already exists in ANY dropzone for this method (if ASAP)
                    var $card = $dropzone.closest('.ass-method-card');
                    var isAsap = $card.find('.ass-type-toggle[value="asap"]:checked').length > 0;

                    if (isAsap) {
                        var alreadyExists = $card.find('.ass-tag-dropzone .ass-tag-pill[data-id="' + tagId + '"]').not(itemEl).length > 0;
                        if (alreadyExists) {
                            $(itemEl).remove();
                            return;
                        }
                    } else {
                        // For BY DATE, check only current dropzone
                        var alreadyExists = $dropzone.find('.ass-tag-pill[data-id="' + tagId + '"]').length > 1;
                        if (alreadyExists) {
                            $(itemEl).remove();
                            return;
                        }
                    }

                    // Replace cloned item with a proper pill + hidden input
                    var inputName = '';
                    if (type === 'asap') {
                        inputName = 'rules[' + methodId + '][tags][]';
                    } else if (type === 'priority_day') {
                        var pIndex = $(itemEl).closest('.ass-priority-day-row').data('index');
                        inputName = 'rules[' + methodId + '][priority_days][' + pIndex + '][tags][]';
                    } else {
                        inputName = 'rules[' + methodId + '][dates][' + dateIndex + '][tags][]';
                    }

                    var pillHtml = '<div class="ass-tag-pill ass-tag-color-' + tagId + '" data-id="' + tagId + '">' +
                        '<span>' + tagName + '</span>' +
                        '<input type="hidden" name="' + inputName + '" value="' + tagId + '">' +
                        '<span class="remove-tag">×</span>' +
                        '</div>';

                    $(itemEl).replaceWith(pillHtml);
                    $(document.body).trigger('ass-form-dirty');
                    var $wrapper = type === 'asap' ? $dropzone.closest('.ass-general-settings-wrapper') : $dropzone.closest('.ass-section-wrapper');
                    if ($wrapper.length) $(document.body).trigger('ass-section-changed', [$wrapper]);
                }
            });
            $dropzone.data('sortable-initialized', true);
        });
    }

    initSortable();

    // Next free row index, derived from the highest existing data-index rather than the
    // row count. Removing a row leaves a gap, so the count would collide with a surviving
    // row: both would post rules[...][dates][N][tags][] and PHP would merge them into one
    // date (duplicated tags), while the colliding [date]/[label] fields silently overwrite
    // each other and lose a row. Gaps are fine - PHP re-packs the array on save.
    function nextRowIndex($container, rowSelector) {
        var max = -1;
        $container.find(rowSelector).each(function() {
            var idx = parseInt($(this).attr('data-index'), 10);
            if (!isNaN(idx) && idx > max) max = idx;
        });
        return max + 1;
    }

    // Add Date Row
    $(document).on('click', '.add-date-row', function() {
        var $repeater = $(this).closest('.ass-dates-repeater');
        var $container = $repeater.find('.ass-dates-container');
        var methodId = $repeater.data('method-id');
        var nextIndex = nextRowIndex($container, '.ass-date-row');

        var template = $('#ass-date-row-template').html();
        var html = template.replace(/{index}/g, nextIndex).replace(/{method_id}/g, methodId);
        
        $container.append(html);
        initSortable(); // Re-init for new dropzone
    });

    // Remove Date Row
    $(document).on('click', '.remove-date-row', function() {
        if (confirm('Are you sure you want to remove this date and its tags?')) {
            $(this).closest('.ass-date-row').remove();
        }
    });

    // Duplicate Row (BY DATE or Priority Day)
    $(document).on('click', '.ass-duplicate-row', function() {
        var $btn = $(this);
        var $row = $btn.closest('.ass-date-row');
        if ($row.length) {
            // Duplicate BY DATE row
            var $repeater = $row.closest('.ass-dates-repeater');
            var $container = $repeater.find('.ass-dates-container');
            var methodId = $repeater.data('method-id');
            var nextIndex = nextRowIndex($container, '.ass-date-row');
            var template = $('#ass-date-row-template').html();
            var html = template.replace(/{index}/g, nextIndex).replace(/{method_id}/g, methodId);
            $container.append(html);
            var $newRow = $container.find('.ass-date-row').last();
            $newRow.attr('data-index', nextIndex);
            var srcIdx = $row.data('index');
            $newRow.find('input[name*="[dates][' + nextIndex + '][date]"]').val($row.find('input[name*="[dates][' + srcIdx + '][date]"]').val());
            $newRow.find('input[name*="[dates][' + nextIndex + '][label]"]').val($row.find('input[name*="[dates][' + srcIdx + '][label]"]').val());
            $newRow.find('input[name*="[dates][' + nextIndex + '][show_until_date]"]').val($row.find('input[name*="[dates][' + srcIdx + '][show_until_date]"]').val());
            $newRow.find('input[name*="[dates][' + nextIndex + '][show_until_time]"]').val($row.find('input[name*="[dates][' + srcIdx + '][show_until_time]"]').val() || '23:59');
            var $srcDrop = $row.find('.ass-tag-dropzone');
            var $tgtDrop = $newRow.find('.ass-tag-dropzone');
            $srcDrop.find('.ass-tag-pill').each(function() {
                var tagId = $(this).data('id');
                var tagName = $(this).text().replace(/\s*×\s*$/, '').trim();
                var pillHtml = '<div class="ass-tag-pill ass-tag-color-' + tagId + '" data-id="' + tagId + '"><span>' + tagName + '</span><input type="hidden" name="rules[' + methodId + '][dates][' + nextIndex + '][tags][]" value="' + tagId + '"><span class="remove-tag">×</span></div>';
                $tgtDrop.append(pillHtml);
            });
            initSortable();
            setTimeout(initTooltips, 100);
            if ($('#ass-rules-form').length) $(document.body).trigger('ass-form-dirty');
            return;
        }
        $row = $btn.closest('.ass-priority-day-row');
        if ($row.length) {
            // Duplicate Priority Day row
            var $repeater = $row.closest('.ass-priority-days-repeater');
            var $container = $repeater.find('.ass-priority-days-container');
            var methodId = $repeater.data('method-id');
            var nextIndex = nextRowIndex($container, '.ass-priority-day-row');
            var template = $('#ass-priority-day-row-template').html();
            var html = template.replace(/{index}/g, nextIndex).replace(/{method_id}/g, methodId);
            $container.append(html);
            var $newRow = $container.find('.ass-priority-day-row').last();
            $newRow.attr('data-index', nextIndex);
            var srcIdx = $row.data('index');
            $newRow.find('input[name*="[priority_days][' + nextIndex + '][date]"]').val($row.find('input[name*="[priority_days][' + srcIdx + '][date]"]').val());
            $newRow.find('input[name*="[priority_days][' + nextIndex + '][label]"]').val($row.find('input[name*="[priority_days][' + srcIdx + '][label]"]').val());
            var $srcDrop = $row.find('.ass-tag-dropzone');
            var $tgtDrop = $newRow.find('.ass-tag-dropzone');
            $srcDrop.find('.ass-tag-pill').each(function() {
                var tagId = $(this).data('id');
                var tagName = $(this).text().replace(/\s*×\s*$/, '').trim();
                var pillHtml = '<div class="ass-tag-pill ass-tag-color-' + tagId + '" data-id="' + tagId + '"><span>' + tagName + '</span><input type="hidden" name="rules[' + methodId + '][priority_days][' + nextIndex + '][tags][]" value="' + tagId + '"><span class="remove-tag">×</span></div>';
                $tgtDrop.append(pillHtml);
            });
            initSortable();
            setTimeout(initTooltips, 100);
            if ($('#ass-rules-form').length) $(document.body).trigger('ass-form-dirty');
        }
    });

    // Priority Days Repeater
    $(document).on('click', '.add-priority-day-row', function() {
        var $repeater = $(this).closest('.ass-priority-days-repeater');
        var $container = $repeater.find('.ass-priority-days-container');
        var methodId = $repeater.data('method-id');
        var nextIndex = nextRowIndex($container, '.ass-priority-day-row');

        var template = $('#ass-priority-day-row-template').html();
        var html = template.replace(/{index}/g, nextIndex).replace(/{method_id}/g, methodId);
        
        $container.append(html);
        initSortable();
        setTimeout(initTooltips, 100);
    });

    $(document).on('click', '.remove-priority-day-row', function() {
        if (confirm('Are you sure you want to remove this priority day and its tags?')) {
            $(this).closest('.ass-priority-day-row').remove();
        }
    });

    /**
     * Plugin Settings Page Logic
     */

    // Holiday Repeater in Settings
    $(document).on('click', '.add-holiday-row', function() {
        var $container = $('.ass-holidays-container');
        var nextIndex = $container.find('.ass-holiday-row').length;
        var template = $('#ass-holiday-row-template').html();
        var html = template.replace(/{index}/g, nextIndex);
        $container.append(html);
    });

    $(document).on('click', '.remove-holiday-row', function() {
        $(this).closest('.ass-holiday-row').remove();
    });

    // Pickup Locations Repeater
    $(document).on('click', '.add-pickup-row', function() {
        var $container = $('.ass-pickup-locations-container');
        var nextIndex = $container.find('.ass-pickup-location-row').length;
        var template = $('#ass-pickup-location-row-template').html();
        var html = template.replace(/{index}/g, nextIndex);
        $container.append(html);
    });

    $(document).on('click', '.remove-pickup-row', function() {
        if (confirm('Are you sure you want to remove this pickup location?')) {
            $(this).closest('.ass-pickup-location-row').remove();
        }
    });

    // Media Picker for Settings and Pickup Locations
    $(document).on('click', '.ass-upload-button', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $wrapper = $button.closest('.ass-image-picker');
        var $preview = $wrapper.find('.ass-image-preview');
        var $inputId = $wrapper.find('.ass-image-id');
        var $removeBtn = $wrapper.find('.ass-remove-image-button');

        var frame = wp.media({
            title: 'Select Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $inputId.val(attachment.id);
            
            var thumbUrl = attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
            $preview.html('<img src="' + thumbUrl + '" style="max-width: 50px; height: auto; display: block; margin-bottom: 5px;">');
            
            $button.text('Change Image');
            $removeBtn.removeClass('hidden');
        });

        frame.open();
    });

    $(document).on('click', '.ass-remove-image-button', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $wrapper = $button.closest('.ass-image-picker');
        var $preview = $wrapper.find('.ass-image-preview');
        var $inputId = $wrapper.find('.ass-image-id');
        var $uploadBtn = $wrapper.find('.ass-upload-button');

        $inputId.val('');
        $preview.empty();
        $button.addClass('hidden');
        $uploadBtn.text('Select Image');
    });

    function initTooltips() {
        $('.ass-help-tip').not('.ass-tooltip-initialized').each(function() {
            var $tip = $(this);
            var tipText = $tip.attr('data-tip');
            
            if (!tipText) {
                return;
            }
            
            // Mark as initialized
            $tip.addClass('ass-tooltip-initialized');
            
            // Show tooltip on hover (read data-tip on each hover so updates are reflected)
            $tip.on('mouseenter', function(e) {
                var currentTip = $tip.attr('data-tip');
                if (!currentTip) return;
                // Remove any existing tooltip
                $('.ass-tooltip-content').remove();
                
                // Create tooltip element
                var $tooltip = $('<div class="ass-tooltip-content"></div>')
                    .text(currentTip)
                    .appendTo('body');
                
                // Calculate position
                var tipOffset = $tip.offset();
                var tipWidth = $tip.outerWidth();
                var tipHeight = $tip.outerHeight();
                var tooltipWidth = $tooltip.outerWidth();
                var tooltipHeight = $tooltip.outerHeight();
                var scrollTop = $(window).scrollTop();
                var scrollLeft = $(window).scrollLeft();
                
                var top = tipOffset.top - tooltipHeight - 8;
                var left = tipOffset.left + (tipWidth / 2) - (tooltipWidth / 2);
                
                if (left < 10) {
                    left = 10;
                } else if (left + tooltipWidth > $(window).width() - 10) {
                    left = $(window).width() - tooltipWidth - 10;
                }
                
                if (top < scrollTop + 10) {
                    top = tipOffset.top + tipHeight + 8;
                    $tooltip.addClass('ass-tooltip-below');
                }
                
                $tooltip.css({
                    top: top + 'px',
                    left: left + 'px'
                }).fadeIn(150);
            });
            
            // Hide tooltip on mouse leave
            $tip.on('mouseleave', function() {
                $('.ass-tooltip-content').fadeOut(150, function() {
                    $(this).remove();
                });
            });
        });
    }

    // Initialize on page load
    initTooltips();

    if (window.MutationObserver) {
        var observer = new MutationObserver(function(mutations) {
            var needsInit = false;
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    for (var i = 0; i < mutation.addedNodes.length; i++) {
                        var node = mutation.addedNodes[i];
                        if (node.nodeType === 1) {
                            if ($(node).hasClass('ass-help-tip') || $(node).find('.ass-help-tip').length) {
                                needsInit = true;
                                break;
                            }
                        }
                    }
                }
            });
            if (needsInit) {
                setTimeout(initTooltips, 100);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    // Also re-init after date row additions
    $(document).on('click', '.add-date-row', function() {
        setTimeout(initTooltips, 100);
        setTimeout(initAssDatePickers, 100);
    });

    function datePrevDay(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() - 1);
        var y = d.getFullYear();
        var m = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        return y + '-' + m + '-' + day;
    }

    function updateRowVisibilityLabel($row) {
        var $label = $row.find('.ass-row-visibility-label');
        if (!$label.length) return;

        var reservationDate = '';
        var showUntilDate = '';
        var showUntilTime = '';

        if ($row.hasClass('ass-date-row')) {
            var idx = $row.data('index');
            reservationDate = $row.find('input[name*="[dates][' + idx + '][date]"]').val() || '';
            showUntilDate = $row.find('input[name*="[dates][' + idx + '][show_until_date]"]').val() || '';
            showUntilTime = $row.find('input[name*="[dates][' + idx + '][show_until_time]"]').val() || '23:59';
        } else if ($row.hasClass('ass-priority-day-row')) {
            var idx = $row.data('index');
            reservationDate = $row.find('input[name*="[priority_days][' + idx + '][date]"]').val() || '';
        }

        if (!reservationDate) {
            $label.text($label.data('empty-text') || '');
            return;
        }

        var now = new Date();
        var nowStr = now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2);

        if ($row.hasClass('ass-priority-day-row')) {
            if (nowStr >= reservationDate) {
                $label.text(assVisibilityLabels.not_shown);
            } else {
                $label.text(assVisibilityLabels.shown_until.replace('%s', datePrevDay(reservationDate) + ' 23:59'));
            }
            return;
        }

        var isHidden = false;
        if (nowStr >= reservationDate) {
            isHidden = true;
        }
        if (!isHidden && showUntilDate) {
            var showUntilFull = showUntilDate + ' ' + showUntilTime;
            var showUntilDt = new Date(showUntilDate + 'T' + showUntilTime + ':00');
            if (now >= showUntilDt) {
                isHidden = true;
            }
        }

        if (isHidden) {
            $label.text(assVisibilityLabels.not_shown);
            $row.addClass('ass-row-not-shown');
        } else {
            $row.removeClass('ass-row-not-shown');
            if (showUntilDate) {
                $label.text(assVisibilityLabels.shown_until.replace('%s', showUntilDate + ' ' + showUntilTime));
            } else {
                $label.text(assVisibilityLabels.shown_until.replace('%s', datePrevDay(reservationDate) + ' 23:59'));
            }
        }
    }

    function autoFillShowUntil($row, reservationDate) {
        if (!$row.hasClass('ass-date-row')) return;
        var idx = $row.data('index');
        var $showUntilDate = $row.find('input[name*="[dates][' + idx + '][show_until_date]"]');
        if (!$showUntilDate.val()) {
            var defaultDate = datePrevDay(reservationDate);
            $showUntilDate.val(defaultDate);
            if ($showUntilDate[0]._flatpickr) {
                $showUntilDate[0]._flatpickr.setDate(defaultDate, false);
            }
        }
        updateRowVisibilityLabel($row);
    }

    // European format date/time pickers (24h, YYYY-MM-DD) - rules page only
    function initAssDatePickers() {
        if (typeof flatpickr === 'undefined' || !$('#ass-rules-form').length) return;
        $('#ass-rules-form input.ass-flatpickr-date').each(function() {
            if (this._flatpickr) return;
            var $input = $(this);
            var isReservationDate = $input.closest('.ass-show-until-group').length === 0 &&
                                    $input.closest('.ass-date-time-inputs').length === 0;
            var isShowUntilDate = $input.closest('.ass-date-time-inputs').length > 0;

            flatpickr(this, {
                dateFormat: 'Y-m-d',
                allowInput: true,
                disableMobile: true,
                onChange: function(selectedDates, dateStr) {
                    $(this.input).trigger('change');
                    var $row = $(this.input).closest('.ass-date-row, .ass-priority-day-row');
                    if (isReservationDate && dateStr && $row.length) {
                        autoFillShowUntil($row, dateStr);
                    }
                    if (isShowUntilDate && $row.length) {
                        updateRowVisibilityLabel($row);
                    }
                }
            });
        });
        $('#ass-rules-form input.ass-flatpickr-time').each(function() {
            if (this._flatpickr) return;
            flatpickr(this, {
                enableTime: true,
                noCalendar: true,
                dateFormat: 'H:i',
                time_24hr: true,
                allowInput: true,
                disableMobile: true,
                onChange: function() {
                    $(this.input).trigger('change');
                    var $row = $(this.input).closest('.ass-date-row');
                    if ($row.length) {
                        updateRowVisibilityLabel($row);
                    }
                }
            });
        });
    }
    if ($('#ass-rules-form').length) {
        initAssDatePickers();
    }
    $(document).on('click', '.add-priority-day-row', function() {
        setTimeout(initAssDatePickers, 100);
    });
    $(document).on('click', '.ass-duplicate-row', function() {
        setTimeout(initAssDatePickers, 100);
    });
});

