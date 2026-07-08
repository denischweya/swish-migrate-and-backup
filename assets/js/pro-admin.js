/**
 * Swish Backup Pro - Admin JavaScript
 *
 * Handles sidebar interactions, theme toggling, and UI enhancements.
 */

( function( $ ) {
    'use strict';

    /**
     * Theme Controller
     * Manages dark/light mode preferences.
     */
    const SwishTheme = {
        storageKey: 'swish_backup_theme',

        init: function() {
            this.loadPreference();
            this.bindEvents();
            this.watchSystemPreference();
        },

        bindEvents: function() {
            const self = this;
            $( document ).on( 'click', '#swish-theme-toggle', function( e ) {
                e.preventDefault();
                self.toggle();
            } );
        },

        toggle: function() {
            const currentTheme = this.getTheme();
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            this.setTheme( newTheme );
        },

        getTheme: function() {
            return $( '.swish-app' ).attr( 'data-theme' ) || 'light';
        },

        setTheme: function( theme ) {
            $( '.swish-app' ).attr( 'data-theme', theme );
            localStorage.setItem( this.storageKey, theme );

            // Save to WordPress user meta via AJAX.
            this.saveToServer( theme );
        },

        loadPreference: function() {
            const saved = localStorage.getItem( this.storageKey );
            if ( saved ) {
                $( '.swish-app' ).attr( 'data-theme', saved );
            }
        },

        watchSystemPreference: function() {
            const self = this;
            const mediaQuery = window.matchMedia( '(prefers-color-scheme: dark)' );

            // Only apply system preference if no saved preference.
            if ( ! localStorage.getItem( this.storageKey ) ) {
                this.setTheme( mediaQuery.matches ? 'dark' : 'light' );
            }

            // Listen for system preference changes.
            mediaQuery.addEventListener( 'change', function( e ) {
                if ( ! localStorage.getItem( self.storageKey ) ) {
                    self.setTheme( e.matches ? 'dark' : 'light' );
                }
            } );
        },

        saveToServer: function( theme ) {
            if ( typeof swishBackupPro === 'undefined' ) {
                return;
            }

            $.ajax( {
                url: swishBackupPro.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'swish_backup_save_theme',
                    theme: theme,
                    nonce: swishBackupPro.nonce
                }
            } );
        }
    };

    /**
     * Sidebar Controller
     * Manages sidebar collapse/expand and mobile interactions.
     */
    const SwishSidebar = {
        storageKey: 'swish_backup_sidebar_collapsed',
        isOpen: false,

        init: function() {
            this.loadState();
            this.bindEvents();
            this.handleResize();
        },

        bindEvents: function() {
            const self = this;

            // Toggle sidebar collapse (desktop).
            $( document ).on( 'click', '.swish-sidebar-toggle-btn', function( e ) {
                e.preventDefault();
                self.toggleCollapse();
            } );

            // Mobile menu toggle.
            $( document ).on( 'click', '.swish-sidebar-toggle', function( e ) {
                e.preventDefault();
                self.toggleMobile();
            } );

            // Close sidebar when clicking outside on mobile.
            $( document ).on( 'click', '.swish-main', function( e ) {
                if ( self.isOpen && window.innerWidth <= 782 ) {
                    self.closeMobile();
                }
            } );

            // Handle window resize.
            $( window ).on( 'resize', $.debounce( 250, function() {
                self.handleResize();
            } ) );
        },

        toggleCollapse: function() {
            const $body = $( 'body' );
            $body.toggleClass( 'swish-sidebar-collapsed' );
            localStorage.setItem( this.storageKey, $body.hasClass( 'swish-sidebar-collapsed' ) );
        },

        loadState: function() {
            const collapsed = localStorage.getItem( this.storageKey ) === 'true';
            if ( collapsed && window.innerWidth > 782 ) {
                $( 'body' ).addClass( 'swish-sidebar-collapsed' );
            }
        },

        toggleMobile: function() {
            this.isOpen = ! this.isOpen;
            $( '.swish-sidebar' ).toggleClass( 'open', this.isOpen );
        },

        closeMobile: function() {
            this.isOpen = false;
            $( '.swish-sidebar' ).removeClass( 'open' );
        },

        handleResize: function() {
            if ( window.innerWidth > 782 ) {
                this.closeMobile();
            }
        }
    };

    /**
     * Filter Bar Controller
     * Handles search and filter interactions.
     */
    const SwishFilterBar = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // Search input.
            $( document ).on( 'input', '#swish-search', $.debounce( 300, function() {
                self.handleSearch( $( this ).val() );
            } ) );

            // Status filter.
            $( document ).on( 'change', '#swish-status-filter', function() {
                self.handleFilter( $( this ).val() );
            } );
        },

        handleSearch: function( query ) {
            $( document ).trigger( 'swish:search', [ query.toLowerCase() ] );
        },

        handleFilter: function( status ) {
            $( document ).trigger( 'swish:filter', [ status ] );
        }
    };

    /**
     * Table Controller
     * Handles table interactions like row selection, sorting, etc.
     */
    const SwishTable = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // Select all checkbox.
            $( document ).on( 'change', '.swish-table-select-all', function() {
                const checked = $( this ).prop( 'checked' );
                $( '.swish-table-row-select' ).prop( 'checked', checked );
                self.updateSelectionState();
            } );

            // Individual row checkbox.
            $( document ).on( 'change', '.swish-table-row-select', function() {
                self.updateSelectionState();
            } );

            // Search filter.
            $( document ).on( 'swish:search', function( e, query ) {
                self.filterBySearch( query );
            } );

            // Status filter.
            $( document ).on( 'swish:filter', function( e, status ) {
                self.filterByStatus( status );
            } );
        },

        updateSelectionState: function() {
            const $checkboxes = $( '.swish-table-row-select' );
            const $selectAll = $( '.swish-table-select-all' );
            const totalChecked = $checkboxes.filter( ':checked' ).length;
            const total = $checkboxes.length;

            $selectAll.prop( 'checked', totalChecked === total && total > 0 );
            $selectAll.prop( 'indeterminate', totalChecked > 0 && totalChecked < total );

            $( document ).trigger( 'swish:selection-changed', [ totalChecked ] );
        },

        filterBySearch: function( query ) {
            $( '.swish-table tbody tr' ).each( function() {
                const $row = $( this );
                const text = $row.text().toLowerCase();
                const matches = query === '' || text.indexOf( query ) !== -1;
                $row.toggle( matches );
            } );
        },

        filterByStatus: function( status ) {
            $( '.swish-table tbody tr' ).each( function() {
                const $row = $( this );
                const rowStatus = $row.data( 'status' ) || '';
                const matches = status === '' || rowStatus === status;
                $row.toggle( matches );
            } );
        },

        getSelectedIds: function() {
            return $( '.swish-table-row-select:checked' ).map( function() {
                return $( this ).val();
            } ).get();
        }
    };

    /**
     * Modal Controller
     * Handles modal open/close interactions.
     */
    const SwishModal = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // Open modal.
            $( document ).on( 'click', '[data-modal-open]', function( e ) {
                e.preventDefault();
                const modalId = $( this ).data( 'modal-open' );
                self.open( modalId );
            } );

            // Close modal.
            $( document ).on( 'click', '.swish-modal-close, .swish-modal-overlay', function( e ) {
                if ( e.target === this ) {
                    self.close();
                }
            } );

            // Close on escape key.
            $( document ).on( 'keydown', function( e ) {
                if ( e.key === 'Escape' ) {
                    self.close();
                }
            } );
        },

        open: function( modalId ) {
            const $modal = $( '#' + modalId );
            if ( $modal.length ) {
                $modal.addClass( 'active' );
                $( 'body' ).addClass( 'swish-modal-open' );
                $modal.find( '[autofocus]' ).focus();
            }
        },

        close: function() {
            $( '.swish-modal-overlay.active' ).removeClass( 'active' );
            $( 'body' ).removeClass( 'swish-modal-open' );
        }
    };

    /**
     * Pagination Controller
     */
    const SwishPagination = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $( document ).on( 'click', '.swish-pagination-btn:not(.active)', function( e ) {
                e.preventDefault();
                const page = $( this ).data( 'page' );
                if ( page ) {
                    $( document ).trigger( 'swish:page-change', [ page ] );
                }
            } );
        }
    };

    /**
     * jQuery debounce utility.
     */
    $.debounce = function( wait, func ) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout( timeout );
            timeout = setTimeout( function() {
                func.apply( context, args );
            }, wait );
        };
    };

    /**
     * Initialize all controllers.
     */
    $( document ).ready( function() {
        // Only initialize on Swish pages.
        if ( $( '.swish-app' ).length ) {
            SwishTheme.init();
            SwishSidebar.init();
            SwishFilterBar.init();
            SwishTable.init();
            SwishModal.init();
            SwishPagination.init();
        }

        // Add Pro badge to admin menu (for non-app pages too).
        addProBadge();

        // Log version.
        if ( typeof swishBackupPro !== 'undefined' ) {
            console.log( 'Swish Backup Pro v' + swishBackupPro.version + ' loaded.' );
        }
    } );

    /**
     * Add Pro badge to admin menu.
     */
    function addProBadge() {
        const $menuItem = $( '#toplevel_page_swish-backup .wp-menu-name' );
        if ( $menuItem.length && ! $menuItem.find( '.swish-pro-badge' ).length ) {
            $menuItem.append( ' <span class="swish-pro-badge">PRO</span>' );
        }
    }

    // Expose controllers for external use.
    window.SwishBackupPro = {
        Theme: SwishTheme,
        Sidebar: SwishSidebar,
        FilterBar: SwishFilterBar,
        Table: SwishTable,
        Modal: SwishModal,
        Pagination: SwishPagination
    };

} )( jQuery );
