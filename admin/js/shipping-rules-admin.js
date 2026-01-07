jQuery(document).ready(function($) {
    /**
     * Rules Page Logic
     */

    // Toggle between ASAP and BY DATE panes
    $(document).on('change', '.ass-type-toggle', function() {
        var $card = $(this).closest('.ass-method-card');
        var type = $(this).val();
        $card.find('.ass-pane').addClass('hidden');
        $card.find('.ass-pane-' + type).removeClass('hidden');
    });

    // Initialize Sortable for category source and dropzones
    function initSortable() {
        // Source list (copying instead of moving)
        var sourceList = $('.ass-category-source')[0];
        if (sourceList && !$(sourceList).data('sortable-initialized')) {
            new Sortable(sourceList, {
                group: {
                    name: 'categories',
                    pull: 'clone',
                    put: false
                },
                sort: false,
                animation: 150
            });
            $(sourceList).data('sortable-initialized', true);
        }

        // Dropzones
        $('.ass-category-dropzone').each(function() {
            var $dropzone = $(this);
            if ($dropzone.data('sortable-initialized')) return;

            new Sortable(this, {
                group: 'categories',
                animation: 150,
                onAdd: function(evt) {
                    var itemEl = evt.item;
                    var catId = $(itemEl).data('id');
                    var catName = $(itemEl).text().trim();
                    var type = $dropzone.data('type');
                    var methodId = $dropzone.data('method-id');
                    var $row = $dropzone.closest('.ass-date-row');
                    var dateIndex = $row.length ? $row.data('index') : null;

                    // Check if already exists in this dropzone
                    var alreadyExists = $dropzone.find('.ass-cat-pill[data-id="' + catId + '"]').length > 1;
                    if (alreadyExists) {
                        $(itemEl).remove();
                        return;
                    }

                    // Replace cloned item with a proper pill + hidden input
                    var inputName = '';
                    if (type === 'asap') {
                        inputName = 'rules[' + methodId + '][categories][]';
                    } else {
                        inputName = 'rules[' + methodId + '][dates][' + dateIndex + '][categories][]';
                    }

                    var pillHtml = '<div class="ass-cat-pill" data-id="' + catId + '">' +
                        '<span>' + catName + '</span>' +
                        '<input type="hidden" name="' + inputName + '" value="' + catId + '">' +
                        '<span class="remove-cat">×</span>' +
                        '</div>';

                    $(itemEl).replaceWith(pillHtml);
                }
            });
            $dropzone.data('sortable-initialized', true);
        });
    }

    initSortable();

    // Remove category pill
    $(document).on('click', '.remove-cat', function() {
        $(this).closest('.ass-cat-pill').remove();
    });

    // Add Date Row
    $(document).on('click', '.add-date-row', function() {
        var $repeater = $(this).closest('.ass-dates-repeater');
        var $container = $repeater.find('.ass-dates-container');
        var methodId = $repeater.data('method-id');
        var nextIndex = $container.find('.ass-date-row').length;
        
        var template = $('#ass-date-row-template').html();
        var html = template.replace(/{index}/g, nextIndex).replace(/{method_id}/g, methodId);
        
        $container.append(html);
        initSortable(); // Re-init for new dropzone
    });

    // Remove Date Row
    $(document).on('click', '.remove-date-row', function() {
        if (confirm('Are you sure you want to remove this date and its categories?')) {
            $(this).closest('.ass-date-row').remove();
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

    function initTooltips() {
        $('.ass-help-tip').not('.ass-tooltip-initialized').each(function() {
            var $tip = $(this);
            var tipText = $tip.attr('data-tip');
            
            if (!tipText) {
                return;
            }
            
            // Mark as initialized
            $tip.addClass('ass-tooltip-initialized');
            
            // Show tooltip on hover
            $tip.on('mouseenter', function(e) {
                // Remove any existing tooltip
                $('.ass-tooltip-content').remove();
                
                // Create tooltip element
                var $tooltip = $('<div class="ass-tooltip-content"></div>')
                    .text(tipText)
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
    });
});

