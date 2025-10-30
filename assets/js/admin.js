jQuery(document).ready(function($) {
    
    // Funcionalidad de colapsar/expandir secciones
    $('.openwebui-section, .openwebui-stats-box, .openwebui-form').each(function() {
        var $section = $(this);
        var $heading = $section.find('h2').first();
        
        if ($heading.length > 0 && !$heading.hasClass('no-collapse')) {
            $heading.css({
                'cursor': 'pointer',
                'user-select': 'none',
                'position': 'relative',
                'padding-right': '30px'
            });
            
            $heading.append('<span class="collapse-icon dashicons dashicons-arrow-up-alt2" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></span>');
            
            $heading.on('click', function() {
                var $content = $section.children().not('h2');
                var $icon = $(this).find('.collapse-icon');
                
                $content.slideToggle(300);
                $icon.toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
            });
        }
    });
    
    // Búsqueda en tablas con debounce
    var searchTimeout;
    $('.openwebui-search').on('keyup', function() {
        clearTimeout(searchTimeout);
        var value = $(this).val().toLowerCase();
        var tableId = $(this).data('table');
        
        searchTimeout = setTimeout(function() {
            $('#' + tableId + ' tbody tr').filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        }, 300);
    });
    
    // Ordenación de tablas
    $('.sortable').on('click', function() {
        var table = $(this).closest('table');
        var tbody = table.find('tbody');
        var rows = tbody.find('tr').toArray();
        var index = $(this).index();
        var order = $(this).hasClass('asc') ? 'desc' : 'asc';
        var isDate = $(this).hasClass('sort-date');
        
        table.find('.sortable').removeClass('asc desc');
        $(this).addClass(order);
        
        rows.sort(function(a, b) {
            var aValue = $(a).find('td').eq(index).text();
            var bValue = $(b).find('td').eq(index).text();
            
            if (isDate) {
                var aDate = parseSpanishDate(aValue);
                var bDate = parseSpanishDate(bValue);
                return order === 'asc' ? aDate - bDate : bDate - aDate;
            } else {
                if (order === 'asc') {
                    return aValue.localeCompare(bValue);
                } else {
                    return bValue.localeCompare(aValue);
                }
            }
        });
        
        tbody.html(rows);
    });
    
    function parseSpanishDate(dateStr) {
        if (!dateStr || dateStr.trim() === '' || dateStr.indexOf('/') === -1) {
            return 0;
        }
        var parts = dateStr.split(' ');
        var dateParts = parts[0].split('/');
        var day = parseInt(dateParts[0], 10);
        var month = parseInt(dateParts[1], 10) - 1;
        var year = parseInt(dateParts[2], 10);
        var hour = 0, minute = 0;
        if (parts[1]) {
            var timeParts = parts[1].split(':');
            hour = parseInt(timeParts[0], 10);
            minute = parseInt(timeParts[1], 10);
        }
        return new Date(year, month, day, hour, minute).getTime();
    }

    function hasGroupsAvailable() {
        return Array.isArray(openwebuiData.groups) && openwebuiData.groups.length > 0;
    }

    function buildGroupOptions() {
        var options = '<option value="">' + openwebuiData.strings.select_group + '</option>';

        if (!hasGroupsAvailable()) {
            return options;
        }

        $.each(openwebuiData.groups, function(index, group) {
            var label = group.name || group.id;
            options += '<option value="' + group.id + '">' + label;
            if (group.member_count) {
                options += ' (' + group.member_count + ')';
            }
            options += '</option>';
        });

        return options;
    }
    
    // Test de conexión API
    $('#test-api').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#test-result');

        $btn.prop('disabled', true).text(openwebuiData.strings.testing);
        $result.html('<span class="spinner is-active" style="float:none;"></span>');

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'test_openwebui_api',
                nonce: openwebuiData.nonce
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: #46b450; font-weight: bold;">✓ ' + response.data + '</span>');
                } else {
                    $result.html('<span style="color: #dc3232; font-weight: bold;">✗ ' + response.data + '</span>');
                }
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: #dc3232;">Error de conexión: ' + error + '</span>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Probar Conexión API');
            }
        });
    });

    // Sincronización bilateral
    $('#bilateral-sync').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#bilateral-sync-result');

        if (!confirm('¿Deseas sincronizar bilateralmente los usuarios? Esto marcará como sincronizados los usuarios de WordPress que ya existan en OpenWebUI.')) {
            return;
        }

        $btn.prop('disabled', true).text('Sincronizando...');
        $result.html('<span class="spinner is-active" style="float:none;"></span>');

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'bilateral_sync_users',
                nonce: openwebuiData.nonce
            },
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    var stats = '<div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 15px; margin-top: 10px;">';
                    stats += '<p style="margin: 0; color: #155724; font-weight: bold;">✓ ' + response.data.message + '</p>';
                    stats += '<ul style="margin: 10px 0 0 20px; color: #155724;">';
                    stats += '<li>Nuevos sincronizados: ' + response.data.synced + '</li>';
                    stats += '<li>Ya sincronizados: ' + response.data.already_synced + '</li>';
                    stats += '<li>No encontrados en OpenWebUI: ' + response.data.not_found + '</li>';
                    stats += '<li>Total usuarios WordPress: ' + response.data.total_wordpress + '</li>';
                    stats += '<li>Total usuarios OpenWebUI: ' + response.data.total_openwebui + '</li>';
                    stats += '</ul></div>';
                    $result.html(stats);

                    // Recargar página después de 2 segundos para mostrar usuarios actualizados
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $result.html('<div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px; margin-top: 10px;"><span style="color: #721c24; font-weight: bold;">✗ Error: ' + response.data + '</span></div>');
                }
            },
            error: function(xhr, status, error) {
                $result.html('<div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px; margin-top: 10px;"><span style="color: #721c24;">Error de conexión: ' + error + '</span></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Sincronizar Bilateralmente');
            }
        });
    });
    
    // Cambiar tipo de filtro
    $('#sync-filter-type').on('change', function() {
        var filterType = $(this).val();
        $('#date-filter-container, #course-filter-container').hide();
        $('#preview-container').hide();

        if (filterType === 'date') {
            $('#date-filter-container').show();
        } else if (filterType === 'course') {
            $('#course-filter-container').show();
            loadCourses();
            loadCourseTags();
        }
    });

    var preloadedCourses = (openwebuiData.groupPanel && Array.isArray(openwebuiData.groupPanel.courses)) ? openwebuiData.groupPanel.courses.slice() : [];
    var groupPanelState = {
        courses: preloadedCourses,
        coursesLoaded: preloadedCourses.length > 0,
        coursesError: '',
        groupsLoading: false,
        groupsError: '',
        selectedCourse: null,
        selectedGroup: null
    };

    if (groupPanelState.coursesLoaded && groupPanelState.courses.length) {
        var firstCourse = groupPanelState.courses[0];
        groupPanelState.selectedCourse = parseInt(firstCourse.id, 10) || null;
        if (groupPanelState.selectedCourse && firstCourse.group_id) {
            groupPanelState.selectedGroup = firstCourse.group_id;
        }
    }

    function escapeHtml(str) {
        return $('<div/>').text(str == null ? '' : str).html();
    }

    initGroupPanel();

    function initGroupPanel() {
        var $panel = $('#openwebui-groups-panel');
        if (!$panel.length) {
            return;
        }

        renderGroupPanelCourses();
        renderGroupPanelGroups();
        updateGroupsMeta();
        loadPanelPreview();

        $('#openwebui-course-search').on('input', function() {
            renderGroupPanelCourses($(this).val());
        });

        $(document).on('click', '.owui-course-item', function(e) {
            e.preventDefault();
            var courseId = parseInt($(this).data('course-id'), 10);
            if (!courseId) {
                return;
            }
            groupPanelState.selectedCourse = courseId;
            var course = findCourse(courseId);
            if (course && course.group_id) {
                groupPanelState.selectedGroup = course.group_id;
            } else if (!findGroup(groupPanelState.selectedGroup)) {
                groupPanelState.selectedGroup = null;
            }
            renderGroupPanelCourses($('#openwebui-course-search').val());
            renderGroupPanelGroups();
            loadPanelPreview();
        });

        $(document).on('click', '.owui-group-item', function(e) {
            e.preventDefault();
            var groupId = $(this).data('group-id');
            if (!groupId) {
                return;
            }
            groupPanelState.selectedGroup = groupId;
            renderGroupPanelGroups();
            loadPanelPreview();
        });

        fetchGroupPanelCourses(groupPanelState.coursesLoaded);

        // Sincronizar grupos automáticamente si no hay grupos disponibles
        if (!Array.isArray(openwebuiData.groups) || !openwebuiData.groups.length) {
            // Destacar el botón de sincronización
            $('#refresh-groups-btn').addClass('button-primary').removeClass('button-secondary');

            // Si autoRefreshGroups está habilitado, sincronizar automáticamente
            if (openwebuiData.groupPanel && openwebuiData.groupPanel.autoRefreshGroups) {
                console.log('Sincronizando grupos automáticamente...');
                refreshGroups(true);
            } else {
                // Mostrar mensaje informativo
                var $meta = $('#openwebui-groups-meta');
                if ($meta.length) {
                    $meta.html('<span class="dashicons dashicons-info"></span><span style="color: #d63638; font-weight: 600;">' +
                              escapeHtml('Debes sincronizar los grupos de OpenWebUI. Pulsa el botón "Actualizar lista de grupos" para comenzar.') +
                              '</span>');
                }
            }
        }
    }

    function findCourse(courseId) {
        return groupPanelState.courses.find(function(course) {
            return parseInt(course.id, 10) === parseInt(courseId, 10);
        });
    }

    function findGroup(groupId) {
        if (!Array.isArray(openwebuiData.groups)) {
            return null;
        }
        return openwebuiData.groups.find(function(group) {
            return String(group.id) === String(groupId);
        }) || null;
    }

    function handleCoursePanelError(message) {
        var finalMessage = message || openwebuiData.strings.panel_courses_error;
        groupPanelState.courses = [];
        groupPanelState.coursesLoaded = true;
        groupPanelState.coursesError = finalMessage;
        groupPanelState.selectedCourse = null;
        groupPanelState.selectedGroup = null;

        $('#openwebui-course-search').prop('disabled', true);
        renderGroupPanelCourses();
        renderGroupPanelGroups();
        loadPanelPreview();
    }

    function fetchGroupPanelCourses(isBackground) {
        var $list = $('#openwebui-courses-list');
        if (!$list.length) {
            return;
        }

        var $search = $('#openwebui-course-search');

        if (!isBackground) {
            groupPanelState.coursesLoaded = false;
            groupPanelState.coursesError = '';
            if ($search.length) {
                $search.prop('disabled', true);
            }
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(openwebuiData.strings.panel_loading_courses) + '</p>');
        }

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'load_group_panel_courses',
                nonce: openwebuiData.nonce
            }
        }).done(function(response) {
            if (response && response.success) {
                var courses = Array.isArray(response.data.courses) ? response.data.courses : [];
                groupPanelState.courses = courses;
                groupPanelState.coursesLoaded = true;
                groupPanelState.coursesError = '';

                if (groupPanelState.selectedCourse && !findCourse(groupPanelState.selectedCourse)) {
                    groupPanelState.selectedCourse = null;
                }

                if (!groupPanelState.selectedCourse && response.data.default_course) {
                    var parsedDefault = parseInt(response.data.default_course, 10);
                    groupPanelState.selectedCourse = parsedDefault ? parsedDefault : response.data.default_course;
                }

                if (groupPanelState.selectedCourse) {
                    var selectedCourse = findCourse(groupPanelState.selectedCourse);
                    if (selectedCourse && selectedCourse.group_id) {
                        groupPanelState.selectedGroup = selectedCourse.group_id;
                    } else if (!groupPanelState.selectedGroup && response.data.default_group) {
                        groupPanelState.selectedGroup = response.data.default_group;
                    }
                }

                var currentSearch = $search.length ? $search.val() : '';
                renderGroupPanelCourses(currentSearch);
                renderGroupPanelGroups();
                loadPanelPreview();

                if ($search.length) {
                    $search.prop('disabled', !courses.length);
                }
            } else {
                var errorMessage = (response && response.data) ? response.data : '';
                if (!errorMessage) {
                    errorMessage = openwebuiData.strings.panel_courses_error;
                }
                handleCoursePanelError(errorMessage);
            }
        }).fail(function(xhr, status, error) {
            var message = openwebuiData.strings.panel_courses_error;
            if (error) {
                message += ' (' + error + ')';
            }
            handleCoursePanelError(message);
        });
    }

    function renderGroupPanelCourses(searchTerm) {
        var $list = $('#openwebui-courses-list');
        if (!$list.length) {
            return;
        }

        var $search = $('#openwebui-course-search');

        if (!groupPanelState.coursesLoaded) {
            if ($search.length) {
                $search.prop('disabled', true);
            }
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(openwebuiData.strings.panel_loading_courses) + '</p>');
            return;
        }

        if (groupPanelState.coursesError) {
            if ($search.length) {
                $search.prop('disabled', true);
            }
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(groupPanelState.coursesError) + '</p>');
            return;
        }

        var courses = Array.isArray(groupPanelState.courses) ? groupPanelState.courses.slice() : [];
        var term = (searchTerm || '').toLowerCase();

        if (term) {
            courses = courses.filter(function(course) {
                var source = course.search ? course.search : (course.title ? course.title.toLowerCase() : '');
                return source.indexOf(term) !== -1;
            });
        }

        if (!groupPanelState.selectedCourse && (!term || term.length === 0) && courses.length) {
            var firstCourse = courses[0];
            groupPanelState.selectedCourse = parseInt(firstCourse.id, 10) || firstCourse.id;
            if (firstCourse.group_id) {
                groupPanelState.selectedGroup = firstCourse.group_id;
            }
        }

        if ($search.length) {
            $search.prop('disabled', !groupPanelState.courses.length);
        }

        if (!courses.length) {
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(openwebuiData.strings.panel_no_courses) + '</p>');
            return;
        }

        var fragment = $(document.createDocumentFragment());

        courses.forEach(function(course) {
            var $item = $('<button/>', {
                type: 'button',
                class: 'owui-course-item',
                'data-course-id': course.id
            });

            if (String(groupPanelState.selectedCourse) === String(course.id)) {
                $item.addClass('is-active');
            }

            var $title = $('<span/>', {
                class: 'owui-course-name',
                text: course.title || ''
            });

            var metaText = course.enrolled_label || '';
            var groupLabel = course.group_label ? course.group_label : openwebuiData.strings.panel_course_group_none;
            if (String(groupPanelState.selectedCourse) === String(course.id) && groupPanelState.selectedGroup) {
                var currentGroup = findGroup(groupPanelState.selectedGroup);
                if (currentGroup && currentGroup.name) {
                    groupLabel = currentGroup.name;
                }
            }

            var $meta = $('<span/>', {
                class: 'owui-course-meta',
                text: metaText
            });

            var $group = $('<span/>', {
                class: 'owui-course-group',
                text: groupLabel
            });

            $item.append($title);
            $item.append($meta);
            $item.append($group);

            if (course.edit_url) {
                var $edit = $('<a/>', {
                    class: 'owui-course-edit',
                    href: course.edit_url,
                    text: openwebuiData.strings.panel_edit_course,
                    target: '_blank',
                    rel: 'noopener noreferrer'
                });
                $item.append($edit);
            }

            fragment.append($item);
        });

        $list.empty().append(fragment);
    }

    function renderGroupPanelGroups() {
        var $list = $('#openwebui-groups-list');
        if (!$list.length) {
            return;
        }

        if (groupPanelState.groupsLoading) {
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(openwebuiData.strings.panel_loading_groups) + '</p>');
            return;
        }

        if (groupPanelState.groupsError) {
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(groupPanelState.groupsError) + '</p>');
            return;
        }

        var groups = Array.isArray(openwebuiData.groups) ? openwebuiData.groups.slice() : [];

        if (!groups.length) {
            $list.html('<p class="openwebui-groups-empty">' + escapeHtml(openwebuiData.strings.panel_no_groups) + '</p>');
            return;
        }

        var fragment = $(document.createDocumentFragment());

        groups.forEach(function(group) {
            var $item = $('<button/>', {
                type: 'button',
                class: 'owui-group-item',
                'data-group-id': group.id
            });

            if (String(groupPanelState.selectedGroup) === String(group.id)) {
                $item.addClass('is-active');
            }

            var $name = $('<span/>', {
                class: 'owui-group-name',
                text: group.name || ''
            });

            var memberCount = typeof group.member_count !== 'undefined' ? group.member_count : '';
            var $count = $('<span/>', {
                class: 'owui-group-count',
                text: memberCount !== '' ? memberCount + '' : ''
            });

            $item.append($name);
            if ($count.text()) {
                $item.append($count);
            }

            if (group.description) {
                $item.append($('<span/>', {
                    class: 'owui-group-description',
                    text: group.description
                }));
            }

            fragment.append($item);
        });

        $list.empty().append(fragment);

        if (!groupPanelState.selectedGroup && groups.length) {
            var course = findCourse(groupPanelState.selectedCourse);
            if (course && course.group_id) {
                groupPanelState.selectedGroup = course.group_id;
                $list.find('.owui-group-item[data-group-id="' + course.group_id + '"]').addClass('is-active');
            }
        }
    }

    function updateGroupsMeta() {
        var $meta = $('#openwebui-groups-meta');
        if (!$meta.length) {
            return;
        }

        if (groupPanelState.groupsLoading) {
            var loadingLabel = openwebuiData.strings.panel_refreshing_groups || openwebuiData.strings.groups_refreshing;
            $meta.html('<span class="dashicons dashicons-update"></span><span>' + escapeHtml(loadingLabel) + '</span>');
            return;
        }

        if (groupPanelState.groupsError) {
            $meta.html('<span class="dashicons dashicons-warning"></span><span>' + escapeHtml(groupPanelState.groupsError) + '</span>');
            return;
        }

        if (openwebuiData.groupsUpdatedLabel) {
            var formatted = openwebuiData.strings.panel_groups_updated_at.replace('%s', openwebuiData.groupsUpdatedLabel);
            $meta.html('<span class="dashicons dashicons-update"></span><span>' + escapeHtml(formatted) + '</span>');
            return;
        }

        if (openwebuiData.groupsUpdated) {
            var label = openwebuiData.strings.panel_groups_updated_at.replace('%s', openwebuiData.groupsUpdated);
            $meta.html('<span class="dashicons dashicons-update"></span><span>' + escapeHtml(label) + '</span>');
        } else {
            $meta.html('<span>' + escapeHtml(openwebuiData.strings.panel_groups_never) + '</span>');
        }
    }

    function loadPanelPreview() {
        var $preview = $('#openwebui-panel-preview');
        if (!$preview.length) {
            return;
        }

        if (!groupPanelState.coursesLoaded) {
            $preview.html('<p class="openwebui-panel-placeholder">' + escapeHtml(openwebuiData.strings.panel_loading_courses) + '</p>');
            return;
        }

        if (groupPanelState.coursesError) {
            $preview.html('<p class="openwebui-panel-placeholder">' + escapeHtml(groupPanelState.coursesError) + '</p>');
            return;
        }

        if (groupPanelState.groupsLoading) {
            $preview.html('<p class="openwebui-panel-placeholder">' + escapeHtml(openwebuiData.strings.panel_loading_groups) + '</p>');
            return;
        }

        if (groupPanelState.groupsError) {
            $preview.html('<p class="openwebui-panel-placeholder">' + escapeHtml(groupPanelState.groupsError) + '</p>');
            return;
        }

        if (!groupPanelState.selectedCourse) {
            $preview.html('<p class="openwebui-panel-placeholder">' + escapeHtml(openwebuiData.strings.panel_course_placeholder) + '</p>');
            return;
        }

        if (!groupPanelState.selectedGroup) {
            $preview.html('<p class="openwebui-panel-placeholder">' + escapeHtml(openwebuiData.strings.panel_group_placeholder) + '</p>');
            return;
        }

        $preview.html('<div class="openwebui-panel-loading">' + escapeHtml(openwebuiData.strings.panel_preview_loading) + '</div>');

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'preview_course_group_panel',
                course_id: groupPanelState.selectedCourse,
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderPanelPreview(response.data);
                } else {
                    $preview.html('<div class="notice notice-error"><p>' + escapeHtml(response.data) + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $preview.html('<div class="notice notice-error"><p>' + escapeHtml(openwebuiData.strings.error + ': ' + error) + '</p></div>');
            }
        });
    }

    function renderPanelPreview(data) {
        var $preview = $('#openwebui-panel-preview');
        if (!$preview.length) {
            return;
        }

        if (!data || !Array.isArray(data.preview) || data.total === 0) {
            $preview.html('<div class="notice notice-info"><p>' + escapeHtml(openwebuiData.strings.panel_preview_empty) + '</p></div>');
            return;
        }

        var summary = '<div class="openwebui-panel-summary">';
        summary += '<ul>';
        summary += '<li><strong>' + escapeHtml(openwebuiData.strings.panel_users_total) + ':</strong> ' + data.total + '</li>';
        summary += '<li><strong>' + escapeHtml(openwebuiData.strings.panel_users_synced) + ':</strong> ' + data.synced + '</li>';
        summary += '<li><strong>' + escapeHtml(openwebuiData.strings.panel_users_pending) + ':</strong> ' + data.pending + '</li>';
        summary += '</ul>';
        summary += '<p class="openwebui-panel-note">' + escapeHtml(openwebuiData.strings.panel_sync_note) + '</p>';
        summary += '</div>';

        var table = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' + escapeHtml(openwebuiData.strings.panel_table_user) + '</th><th>' + escapeHtml(openwebuiData.strings.panel_table_email) + '</th><th>' + escapeHtml(openwebuiData.strings.panel_table_status) + '</th></tr></thead><tbody>';
        data.preview.forEach(function(user) {
            table += '<tr>';
            table += '<td>' + escapeHtml(user.login) + '</td>';
            table += '<td>' + escapeHtml(user.email) + '</td>';
            table += '<td>' + (user.synced ? '<span class="owui-badge owui-badge--synced">' + escapeHtml(openwebuiData.strings.panel_user_synced) + '</span>' : '<span class="owui-badge owui-badge--pending">' + escapeHtml(openwebuiData.strings.panel_user_pending) + '</span>') + '</td>';
            table += '</tr>';
        });
        table += '</tbody></table>';

        if (data.has_more) {
            table += '<p class="openwebui-panel-note">' + escapeHtml(openwebuiData.strings.panel_preview_more.replace('%d', data.preview_limit)) + '</p>';
        }

        var actions = '<div class="openwebui-panel-actions">';
        actions += '<button type="button" class="button button-primary" id="owui-panel-add" data-course-id="' + groupPanelState.selectedCourse + '" data-group-id="' + escapeHtml(groupPanelState.selectedGroup) + '">' + escapeHtml(openwebuiData.strings.panel_action_add) + '</button>';
        actions += '<button type="button" class="button" id="owui-panel-remove" data-course-id="' + groupPanelState.selectedCourse + '" data-group-id="' + escapeHtml(groupPanelState.selectedGroup) + '">' + escapeHtml(openwebuiData.strings.panel_action_remove) + '</button>';
        actions += '</div>';
        actions += '<div id="owui-panel-status"></div>';

        $preview.html(summary + table + actions);
    }

    function updateCourseGroupLabels() {
        if (!Array.isArray(groupPanelState.courses) || !Array.isArray(openwebuiData.groups)) {
            return;
        }

        groupPanelState.courses = groupPanelState.courses.map(function(course) {
            if (course.group_id) {
                var group = findGroup(course.group_id);
                course.group_label = group ? group.name : '';
            }
            return course;
        });
    }

    $(document).on('click', '#owui-panel-add', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var courseId = $btn.data('course-id');
        var groupId = $btn.data('group-id');

        if (!groupId) {
            alert(openwebuiData.strings.select_group);
            return;
        }

        if (!confirm(openwebuiData.strings.confirm_group_assignment)) {
            return;
        }

        var originalText = $btn.text();

        handleGroupAction({
            button: $btn,
            otherButton: $('#owui-panel-remove'),
            payload: {
                action: 'assign_group_members',
                group_id: groupId,
                filter_type: 'course',
                filter_value: courseId,
                nonce: openwebuiData.nonce
            },
            successRenderer: buildAssignmentSummary,
            completeLabel: originalText
        });
    });

    $(document).on('click', '#owui-panel-remove', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var courseId = $btn.data('course-id');
        var groupId = $btn.data('group-id');

        if (!groupId) {
            alert(openwebuiData.strings.select_group);
            return;
        }

        if (!confirm(openwebuiData.strings.panel_remove_confirm)) {
            return;
        }

        var originalText = $btn.text();

        handleGroupAction({
            button: $btn,
            otherButton: $('#owui-panel-add'),
            payload: {
                action: 'remove_group_members',
                group_id: groupId,
                filter_type: 'course',
                filter_value: courseId,
                nonce: openwebuiData.nonce
            },
            successRenderer: buildRemovalSummary,
            completeLabel: originalText
        });
    });

    function handleGroupAction(options) {
        var $status = $('#owui-panel-status');
        if (!$status.length) {
            return;
        }

        var $button = options.button;
        var $other = options.otherButton && options.otherButton.length ? options.otherButton : null;

        $button.prop('disabled', true).text(openwebuiData.strings.panel_action_processing);
        if ($other) {
            $other.prop('disabled', true);
        }

        $status.html('<div class="spinner is-active"></div>');

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: options.payload,
            success: function(response) {
                if (response.success) {
                    $status.html(options.successRenderer(response.data));
                    loadPanelPreview();
                } else {
                    $status.html('<div class="notice notice-error"><p>' + escapeHtml(response.data) + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $status.html('<div class="notice notice-error"><p>' + escapeHtml(openwebuiData.strings.error + ': ' + error) + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text(options.completeLabel);
                if ($other) {
                    $other.prop('disabled', false);
                }
            }
        });
    }

    function buildAssignmentSummary(data) {
        var html = '<div class="notice notice-success"><p><strong>' + escapeHtml(openwebuiData.strings.group_assignment_complete) + '</strong></p>';
        html += '<ul>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_assignment_added) + ': ' + (data.added || 0) + '</li>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_assignment_already) + ': ' + (data.already || 0) + '</li>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_assignment_skipped) + ': ' + (data.skipped ? data.skipped.length : 0) + '</li>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_assignment_failed) + ': ' + (data.errors ? data.errors.length : 0) + '</li>';
        html += '</ul></div>';

        if (data.errors && data.errors.length) {
            html += '<div class="notice notice-warning"><p><strong>' + escapeHtml(openwebuiData.strings.group_assignment_failed) + '</strong></p><ul>';
            data.errors.forEach(function(item) {
                html += '<li>' + escapeHtml(item) + '</li>';
            });
            html += '</ul></div>';
        }

        if (data.skipped && data.skipped.length) {
            html += '<div class="notice notice-warning"><p>' + escapeHtml(openwebuiData.strings.group_assignment_skipped) + ':</p><ul>';
            data.skipped.forEach(function(item) {
                html += '<li>' + escapeHtml(item) + '</li>';
            });
            html += '</ul></div>';
        }

        return html;
    }

    function buildRemovalSummary(data) {
        var html = '<div class="notice notice-success"><p><strong>' + escapeHtml(openwebuiData.strings.group_removal_complete) + '</strong></p>';
        html += '<ul>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_removal_removed) + ': ' + (data.removed || 0) + '</li>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_removal_missing) + ': ' + (data.missing || 0) + '</li>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_assignment_skipped) + ': ' + (data.skipped ? data.skipped.length : 0) + '</li>';
        html += '<li>' + escapeHtml(openwebuiData.strings.group_removal_failed) + ': ' + (data.errors ? data.errors.length : 0) + '</li>';
        html += '</ul></div>';

        if (data.errors && data.errors.length) {
            html += '<div class="notice notice-warning"><p><strong>' + escapeHtml(openwebuiData.strings.group_removal_failed) + '</strong></p><ul>';
            data.errors.forEach(function(item) {
                html += '<li>' + escapeHtml(item) + '</li>';
            });
            html += '</ul></div>';
        }

        if (data.skipped && data.skipped.length) {
            html += '<div class="notice notice-warning"><p>' + escapeHtml(openwebuiData.strings.group_assignment_skipped) + ':</p><ul>';
            data.skipped.forEach(function(item) {
                html += '<li>' + escapeHtml(item) + '</li>';
            });
            html += '</ul></div>';
        }

        return html;
    }

    function handleGroupsError(message, $result) {
        var finalMessage = message || openwebuiData.strings.panel_groups_error;
        groupPanelState.groupsError = finalMessage;
        groupPanelState.groupsLoading = false;
        openwebuiData.groups = [];

        if ($result && $result.length) {
            $result.html('<span style="color:#d63638; font-weight:bold;">' + escapeHtml(finalMessage) + '</span>');
        }

        renderGroupPanelGroups();
        updateGroupsMeta();
        loadPanelPreview();
    }

    function refreshGroups(isAuto) {
        var $btn = $('#refresh-groups-btn');
        var $result = $('#refresh-groups-result');
        var originalText = $btn.length ? $btn.text() : '';
        var loadingLabel = openwebuiData.strings.panel_refreshing_groups || openwebuiData.strings.groups_refreshing;

        groupPanelState.groupsError = '';
        groupPanelState.groupsLoading = true;
        renderGroupPanelGroups();
        updateGroupsMeta();

        if ($btn.length) {
            if (!isAuto) {
                $btn.prop('disabled', true).text(loadingLabel);
            } else {
                $btn.prop('disabled', true);
            }
        }

        if ($result.length) {
            $result.html('<span class="spinner is-active" style="float:none;"></span>');
        }

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'sync_openwebui_groups',
                nonce: openwebuiData.nonce
            }
        }).done(function(response) {
            if (response && response.success) {
                openwebuiData.groups = Array.isArray(response.data.groups) ? response.data.groups : [];
                openwebuiData.groupsUpdated = response.data.updated_at || '';
                openwebuiData.groupsUpdatedLabel = response.data.updated_label || '';

                updateCourseGroupLabels();
                renderGroupPanelCourses($('#openwebui-course-search').val());

                if (groupPanelState.selectedGroup && !findGroup(groupPanelState.selectedGroup)) {
                    var selectedCourse = findCourse(groupPanelState.selectedCourse);
                    groupPanelState.selectedGroup = selectedCourse && selectedCourse.group_id ? selectedCourse.group_id : null;
                }

                var count = parseInt(response.data.count || openwebuiData.groups.length || 0, 10);

                // Restaurar el estilo del botón si se sincronizaron grupos exitosamente
                if (count > 0 && $btn.length) {
                    $btn.removeClass('button-primary').addClass('button-secondary');
                }

                if ($result.length) {
                    if (count > 0) {
                        var successLabel = openwebuiData.strings.groups_refreshed;
                        if (openwebuiData.strings.groups_refreshed_count) {
                            successLabel += ' ' + openwebuiData.strings.groups_refreshed_count.replace('%d', count);
                        }
                        $result.html('<span style="color:#46b450; font-weight:bold;">' + escapeHtml(successLabel) + '</span>');

                        // Ocultar el mensaje de éxito después de 5 segundos
                        setTimeout(function() {
                            $result.fadeOut(400, function() {
                                $(this).html('').show();
                            });
                        }, 5000);

                        // Recargar los cursos para actualizar las etiquetas de grupo
                        console.log('Recargando cursos después de sincronizar grupos...');
                        fetchGroupPanelCourses(false);
                    } else {
                        var warningText = response.data.notice ? response.data.notice : openwebuiData.strings.panel_refresh_empty;
                        $result.html('<span style="color:#dba617; font-weight:bold;">' + escapeHtml(warningText) + '</span>');
                    }
                }

                groupPanelState.groupsError = '';
                renderGroupPanelGroups();
                updateGroupsMeta();
                loadPanelPreview();
            } else {
                var errorMessage = (response && response.data) ? response.data : openwebuiData.strings.groups_refresh_error;
                handleGroupsError(errorMessage, $result);
            }
        }).fail(function(xhr, status, error) {
            var message = openwebuiData.strings.groups_refresh_error;
            if (error) {
                message += ' (' + error + ')';
            }
            handleGroupsError(message, $result);
        }).always(function() {
            groupPanelState.groupsLoading = false;
            renderGroupPanelGroups();
            updateGroupsMeta();

            if ($btn.length) {
                $btn.prop('disabled', false).text(originalText);
            }
        });
    }

    // Sincronizar grupos de OpenWebUI
    $('#refresh-groups-btn').on('click', function(e) {
        e.preventDefault();
        refreshGroups(false);
    });
    
    // Cargar etiquetas de cursos
    function loadCourseTags() {
        var $select = $('#sync-filter-course-tag');
        $select.html('<option value="">⏳ Cargando etiquetas...</option>').prop('disabled', true);
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_course_tags',
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var options = '<option value="">' + openwebuiData.strings.all_tags + '</option>';
                    $.each(response.data, function(i, tag) {
                        options += '<option value="' + tag.id + '">' + tag.name + ' (' + tag.count + ')</option>';
                    });
                    $select.html(options).prop('disabled', false);
                } else {
                    $select.html('<option value="">Sin etiquetas</option>').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $select.html('<option value="">❌ Error al cargar</option>').prop('disabled', false);
            }
        });
    }
    
    // Variable global para almacenar todas las opciones
    var allCourseOptions = [];
    
    // Filtrar cursos por búsqueda y etiqueta
    $('#course-search-input').on('keyup', function() {
        filterCourses();
    });
    
    $('#sync-filter-course-tag').on('change', function() {
        filterCourses();
    });
    
    function filterCourses() {
        var searchText = $('#course-search-input').val().toLowerCase().trim();
        var selectedTag = $('#sync-filter-course-tag').val();
        
        var visibleCount = 0;
        var totalCount = allCourseOptions.length;
        var filteredOptions = '<option value="">-- Seleccionar curso --</option>';
        
        // Recorrer todas las opciones guardadas
        $.each(allCourseOptions, function(index, optionData) {
            var optionText = optionData.text.toLowerCase();
            var optionTags = optionData.tags || '';
            
            // Verificar si coincide con la búsqueda de texto
            var matchesSearch = searchText === '' || optionText.indexOf(searchText) > -1;
            
            // Verificar si coincide con la etiqueta
            var matchesTag = true;
            if (selectedTag && selectedTag !== '') {
                if (optionTags && optionTags !== '') {
                    var tagArray = optionTags.split(',');
                    matchesTag = tagArray.indexOf(selectedTag) !== -1;
                } else {
                    matchesTag = false;
                }
            }
            
            // Añadir a las opciones filtradas si coincide
            if (matchesSearch && matchesTag) {
                filteredOptions += '<option value="' + optionData.value + '" data-tags="' + optionData.tags + '">' + 
                                   optionData.text + '</option>';
                visibleCount++;
            }
        });
        
        // Actualizar el select con las opciones filtradas
        $('#sync-filter-course').html(filteredOptions);
        
        // Actualizar contadores
        $('#visible-courses-count').text(visibleCount);
        $('#total-courses-count').text(totalCount);
        
        // Actualizar mensaje si no hay resultados
        if (visibleCount === 0 && totalCount > 0) {
            if ($('#no-results-message').length === 0) {
                $('#sync-filter-course').after(
                    '<div id="no-results-message" style="padding: 10px; background: #fff8e5; border: 1px solid #ffb900; border-radius: 4px; margin-top: 10px; color: #646970;">' +
                    '⚠️ No se encontraron cursos con estos filtros. Intenta con otros criterios.' +
                    '</div>'
                );
            }
        } else {
            $('#no-results-message').remove();
        }
    }
    
    // Cargar cursos
    function loadCourses() {
        var $select = $('#sync-filter-course');
        var $searchInput = $('#course-search-input');
        var $tagSelect = $('#sync-filter-course-tag');
        
        $select.html('<option value="">⏳ Cargando cursos...</option>');
        $searchInput.val('').prop('disabled', true);
        $tagSelect.prop('disabled', true);
        $('#visible-courses-count').text('0');
        $('#total-courses-count').text('0');
        
        // Limpiar opciones guardadas
        allCourseOptions = [];
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_courses_list',
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var options = '<option value="">-- Seleccionar curso --</option>';
                    
                    // Guardar todas las opciones en el array global
                    $.each(response.data, function(i, course) {
                        allCourseOptions.push({
                            value: course.id,
                            text: course.title,
                            tags: course.tags || ''
                        });
                        
                        options += '<option value="' + course.id + '" data-tags="' + (course.tags || '') + '">' + 
                                   course.title + '</option>';
                    });
                    
                    $select.html(options);
                    $searchInput.prop('disabled', false);
                    $tagSelect.prop('disabled', false);
                    
                    // Actualizar contador inicial
                    $('#visible-courses-count').text(response.data.length);
                    $('#total-courses-count').text(response.data.length);
                } else {
                    $select.html('<option value="">❌ No hay cursos disponibles</option>');
                    $('#visible-courses-count').text('0');
                    $('#total-courses-count').text('0');
                }
            },
            error: function() {
                $select.html('<option value="">❌ Error al cargar cursos</option>');
                $searchInput.prop('disabled', false);
                $tagSelect.prop('disabled', false);
            }
        });
    }
    
    // Previsualizar usuarios por fecha
    $('#preview-by-date-btn').on('click', function(e) {
        e.preventDefault();
        
        var dateFrom = $('#sync-filter-date-from').val();
        var dateTo = $('#sync-filter-date-to').val();
        
        if (!dateFrom || !dateTo) {
            alert(openwebuiData.strings.select_dates);
            return;
        }
        
        var $btn = $(this);
        var $preview = $('#preview-container');
        
        $btn.prop('disabled', true).text(openwebuiData.strings.loading);
        $preview.html('<div class="spinner is-active"></div>');
        $preview.show();
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'preview_users_by_date',
                date_from: dateFrom,
                date_to: dateTo,
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayPreview(response.data, 'date', JSON.stringify({from: dateFrom, to: dateTo}));
                } else {
                    $preview.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            },
            error: function() {
                $preview.html('<div class="notice notice-error"><p>Error al cargar usuarios</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Previsualizar Usuarios');
            }
        });
    });
    
    // Previsualizar usuarios por curso
    $('#preview-by-course-btn').on('click', function(e) {
        e.preventDefault();
        
        var courseId = $('#sync-filter-course').val();
        
        if (!courseId) {
            alert(openwebuiData.strings.select_course);
            return;
        }
        
        var $btn = $(this);
        var $preview = $('#preview-container');
        
        $btn.prop('disabled', true).text(openwebuiData.strings.loading);
        $preview.html('<div class="spinner is-active"></div>');
        $preview.show();
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'preview_users_by_course',
                course_id: courseId,
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayPreview(response.data, 'course', courseId);
                } else {
                    $preview.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            },
            error: function() {
                $preview.html('<div class="notice notice-error"><p>Error al cargar usuarios</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Previsualizar Usuarios');
            }
        });
    });
    
    // Mostrar previsualización
    function displayPreview(data, filterType, filterValue) {
        var $preview = $('#preview-container');
        
        if (data.users.length === 0) {
            $preview.html('<div class="notice notice-warning"><p>No se encontraron usuarios con estos filtros.</p></div>');
            return;
        }
        
        var html = '<div class="notice notice-info"><p><strong>' + data.users.length + ' usuarios</strong> encontrados para sincronizar:</p></div>';
        html += '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
        html += '<thead><tr><th>Usuario</th><th>Email</th><th>Fecha Registro</th></tr></thead><tbody>';

        $.each(data.users, function(i, user) {
            html += '<tr>';
            html += '<td>' + user.login + '</td>';
            html += '<td>' + user.email + '</td>';
            html += '<td>' + user.registered + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        html += '<div style="margin-top: 20px;">';
        html += '<button type="button" class="button button-primary" id="confirm-sync-btn" ';
        html += 'data-filter-type="' + filterType + '" data-filter-value="' + filterValue + '">';
        html += 'Confirmar y Sincronizar ' + data.users.length + ' Usuarios</button>';
        html += '</div>';
        html += '<div id="sync-progress" style="margin-top: 15px;"></div>';

        if (filterType === 'course') {
            html += '<div class="openwebui-group-action" style="margin-top: 20px;">';

            if (hasGroupsAvailable()) {
                html += '<h3 style="margin-top:0;">Asignar usuarios a un grupo de OpenWebUI</h3>';
                html += '<p style="margin-bottom:10px; color:#646970;">Selecciona un grupo sincronizado y añade a todos los usuarios del curso en un solo paso.</p>';
                html += '<div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">';
                html += '<label for="openwebui-group-select" style="min-width:240px; font-weight:600;">' + openwebuiData.strings.select_group + '</label>';
                html += '<select id="openwebui-group-select" style="min-width:260px; padding:6px 8px;">' + buildGroupOptions() + '</select>';
                html += '<button type="button" class="button button-secondary" id="assign-group-btn" data-filter-type="' + filterType + '" data-filter-value="' + filterValue + '">';
                html += openwebuiData.strings.assign_group + '</button>';
                html += '</div>';
                html += '<div id="group-progress" style="margin-top:15px;"></div>';
            } else {
                html += '<div class="notice notice-warning"><p>' + openwebuiData.strings.group_sync_required + '</p></div>';
            }

            html += '</div>';
        }

        $preview.html(html);
    }
    
    // Confirmar sincronización
    $(document).on('click', '#confirm-sync-btn', function(e) {
        e.preventDefault();
        
        if (!confirm(openwebuiData.strings.confirm_sync)) {
            return;
        }
        
        var $btn = $(this);
        var filterType = $btn.data('filter-type');
        var filterValue = $btn.data('filter-value');
        var $progress = $('#sync-progress');
        
        $btn.prop('disabled', true).text(openwebuiData.strings.syncing);
        $progress.html('<div class="notice notice-info"><p>Iniciando sincronización...</p></div>');
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_user_to_openwebui',
                sync_all: true,
                filter_type: filterType,
                filter_value: filterValue,
                nonce: openwebuiData.nonce
            },
            timeout: 300000,
            success: function(response) {
                if (response.success) {
                    var html = '<div class="notice notice-success"><p><strong>✓ ' + response.data.message + '</strong></p>';
                    html += '<p>Usuarios procesados: ' + response.data.processed + '</p>';
                    html += '<p>Usuarios sincronizados: ' + response.data.synced + '</p></div>';
                    $progress.html(html);
                    
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $progress.html('<div class="notice notice-error"><p>✗ ' + response.data + '</p></div>');
                    $btn.prop('disabled', false).text('Confirmar y Sincronizar');
                }
            },
            error: function(xhr, status, error) {
                $progress.html('<div class="notice notice-error"><p>Error: ' + error + '</p></div>');
                $btn.prop('disabled', false).text('Confirmar y Sincronizar');
            }
        });
    });

    // Asignar usuarios al grupo seleccionado
    $(document).on('click', '#assign-group-btn', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var groupId = $('#openwebui-group-select').val();

        if (!groupId) {
            alert(openwebuiData.strings.select_group);
            return;
        }

        if (!confirm(openwebuiData.strings.confirm_group_assignment)) {
            return;
        }

        var filterType = $btn.data('filter-type');
        var filterValue = $btn.data('filter-value');
        var $progress = $('#group-progress');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(openwebuiData.strings.assigning_group);
        $progress.html('<div class="notice notice-info"><p>' + openwebuiData.strings.assigning_group + '</p></div>');

        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'assign_group_members',
                group_id: groupId,
                filter_type: filterType,
                filter_value: filterValue,
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var summary = '<div class="notice notice-success"><p><strong>' + openwebuiData.strings.group_assignment_complete + '</strong></p>';
                    summary += '<ul style="margin:10px 0 0 18px;">';
                    summary += '<li>' + openwebuiData.strings.group_assignment_added + ': ' + data.added + '</li>';
                    summary += '<li>' + openwebuiData.strings.group_assignment_already + ': ' + data.already + '</li>';
                    summary += '<li>' + openwebuiData.strings.group_assignment_skipped + ': ' + data.skipped.length + '</li>';
                    summary += '<li>' + openwebuiData.strings.group_assignment_failed + ': ' + data.errors.length + '</li>';
                    summary += '</ul>';
                    summary += '</div>';

                    if (data.errors.length > 0) {
                        summary += '<div class="notice notice-warning" style="margin-top:10px;"><p><strong>Detalles:</strong></p><ul style="margin:10px 0 0 18px;">';
                        $.each(data.errors, function(_, item) {
                            summary += '<li>' + item + '</li>';
                        });
                        summary += '</ul></div>';
                    }

                    if (data.skipped.length > 0) {
                        summary += '<div class="notice notice-warning" style="margin-top:10px;"><p>' + openwebuiData.strings.group_assignment_skipped + ':</p><ul style="margin:10px 0 0 18px;">';
                        $.each(data.skipped, function(_, email) {
                            summary += '<li>' + email + '</li>';
                        });
                        summary += '</ul></div>';
                    }

                    $progress.html(summary);
                } else {
                    $progress.html('<div class="notice notice-error"><p>' + openwebuiData.strings.group_assignment_error + ': ' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $progress.html('<div class="notice notice-error"><p>' + openwebuiData.strings.group_assignment_error + ': ' + error + '</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Sincronizar usuario individual
    $('.sync-user-btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var $row = $btn.closest('tr');
        
        $btn.prop('disabled', true).text(openwebuiData.strings.syncing);
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_user_to_openwebui',
                user_id: userId,
                nonce: openwebuiData.nonce
            },
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(400, function() {
                        $(this).remove();
                        if ($('.sync-user-btn').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    alert(openwebuiData.strings.error + ': ' + response.data);
                    $btn.prop('disabled', false).text('Sincronizar');
                }
            },
            error: function() {
                alert('Error de conexión');
                $btn.prop('disabled', false).text('Sincronizar');
            }
        });
    });
    
    // Desmarcar usuario como sincronizado
    $('.unsync-user-btn').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(openwebuiData.strings.confirm_unsync)) {
            return;
        }
        
        var $btn = $(this);
        var userId = $btn.data('user-id');
        
        $btn.prop('disabled', true).text('Procesando...');
        
        $.ajax({
            url: openwebuiData.ajaxurl,
            type: 'POST',
            data: {
                action: 'unsync_user_from_openwebui',
                user_id: userId,
                nonce: openwebuiData.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(openwebuiData.strings.error + ': ' + response.data);
                    $btn.prop('disabled', false).text('Desmarcar');
                }
            },
            error: function() {
                alert('Error de conexión');
                $btn.prop('disabled', false).text('Desmarcar');
            }
        });
    });
});
