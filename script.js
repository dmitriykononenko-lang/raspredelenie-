define(['jquery', 'underscore'], function($, _) {
    'use strict';

    var CustomWidget = function() {
        var self = this;

        // self.system() инжектится загрузчиком amoCRM ПОЗЖЕ — в конструкторе
        // его вызывать нельзя (краш). Читаем лениво и безопасно.
        function amoSystem() {
            try { return self.system() || {}; } catch (e) { return {}; }
        }

        // ID аккаunta amoCRM. ВАЖНО: self.system() его НЕ содержит — id живёт
        // в AMOCRM.constant('account').id. Раньше брали amoSystem().account_id
        // → заголовок X-Account-Id уходил пустым → бэкенд отвечал 400
        // "account_id required" ("Не удалось загрузить статусы/расписания").
        function getAccountId() {
            try {
                var acc = window.AMOCRM && AMOCRM.constant && AMOCRM.constant('account');
                if (acc && acc.id) return String(acc.id);
            } catch (e) {}
            var sys = amoSystem();
            if (sys && sys.account_id) return String(sys.account_id);
            if (sys && sys.subdomain)  return String(sys.subdomain);
            return '';
        }

        // ─── Constants ────────────────────────────────────────────────────────
        var WIDGET_VERSION = '1.0.25';

        // Адрес бэкенда по умолчанию — вшит, чтобы виджет работал сразу после
        // установки без заполнения поля. Поле server_url в настройках остаётся
        // необязательным переопределением (если сервер сменится).
        var DEFAULT_SERVER_URL = 'https://raspredelenie.koagency.ru';

        var DISTRIBUTION_METHODS = {
            ROUND_ROBIN: 'round_robin',
            WORKLOAD:    'workload'
        };

        var DEFAULT_SETTINGS = {
            server_url:          '',
            security_key:        '',
            distribution_method: DISTRIBUTION_METHODS.ROUND_ROBIN,
            rules:               []
        };

        // ─── Helpers ──────────────────────────────────────────────────────────
        function getSettings() {
            // v2: настройки читаются через get_settings(); фолбэк на self.params.
            var p = self.params;
            try {
                if (typeof self.get_settings === 'function') {
                    var s = self.get_settings();
                    if (s) p = s;
                }
            } catch (e) {}
            return $.extend(true, {}, DEFAULT_SETTINGS, p || {});
        }

        function notify(text, type) {
            // type: 'success' | 'error' | 'info'
            type = type || 'info';
            if (window.AMOCRM && AMOCRM.notifications) {
                AMOCRM.notifications.show_message({
                    header:  self.i18n('notifications.' + type + '_header'),
                    text:    text,
                    timeout: 3000
                });
            }
        }

        // ─── API calls to our backend ─────────────────────────────────────────
        function apiRequest(path, data, method) {
            var settings  = getSettings();
            // Вшитый адрес по умолчанию; поле в настройках переопределяет его.
            var serverUrl = $.trim(settings.server_url || DEFAULT_SERVER_URL).replace(/\/$/, '');

            if (!serverUrl) {
                console.warn('[DealDist] server_url is not configured');
                return $.Deferred().reject('no_server_url').promise();
            }

            var isReadOnly = (method === 'GET' || method === 'DELETE');
            var ajaxOpts = {
                url:      serverUrl + path,
                type:     method || 'POST',
                dataType: 'json',
                headers: {
                    'X-Account-Id':     getAccountId(),
                    'X-Widget-Version': WIDGET_VERSION
                }
            };
            // Общий секрет виджет↔бэкенд (совпадает с WIDGET_SECRET в .env).
            // Передаём заголовком, не в URL (см. AMOCRM-WIDGET-BILLING-LESSONS §2.4).
            if (settings.security_key) {
                ajaxOpts.headers['X-Security-Key'] = $.trim(settings.security_key);
            }
            if (!isReadOnly) {
                ajaxOpts.contentType = 'application/json';
                ajaxOpts.data        = JSON.stringify(data || {});
            }
            return $.ajax(ajaxOpts);
        }

        function bindSettingsEvents($container, settings) {
            // "Add rule" button
            $container.on('click', '.js-add-rule', function() {
                addRuleRow($container, {});
            });

            // "Remove rule" button
            $container.on('click', '.js-remove-rule', function() {
                $(this).closest('.dist-rule-row').remove();
                recalcRuleIndexes($container);
            });

            // Render existing rules
            _.each(settings.rules || [], function(rule) {
                addRuleRow($container, rule);
            });

            // Pipeline selector change → reload stages
            $container.on('change', '.js-rule-pipeline', function() {
                var $row      = $(this).closest('.dist-rule-row');
                var pipelineId = $(this).val();
                loadStages(pipelineId, $row);
            });
        }

        function addRuleRow($container, rule) {
            var $rulesContainer = $container.find('.js-rules-list');
            var rowHtml = buildRuleRowHtml(rule);
            $rulesContainer.append(rowHtml);
            var $row = $rulesContainer.find('.dist-rule-row').last();

            loadPipelinesIntoRow($row, rule);
        }

        function buildRuleRowHtml(rule) {
            var f = rule.filters || {};
            return [
                '<div class="dist-rule-row">',
                '  <div class="dist-rule-row__header">',
                '    <span class="dist-rule-row__title"><span class="rule-number"></span></span>',
                '    <button type="button" class="js-remove-rule dist-btn dist-btn--danger dist-btn--sm">&#x2715;</button>',
                '  </div>',
                '  <div class="dist-rule-row__body">',

                // Pipeline + Stage
                '    <div class="dist-row-2col">',
                '      <div class="dist-field">',
                '        <label class="dist-label">Воронка</label>',
                '        <select class="js-rule-pipeline dist-select" name="pipeline_id">',
                '          <option value="">— выберите —</option>',
                '        </select>',
                '      </div>',
                '      <div class="dist-field">',
                '        <label class="dist-label">Этап</label>',
                '        <select class="js-rule-stage dist-select" name="stage_id">',
                '          <option value="">Любой</option>',
                '        </select>',
                '      </div>',
                '    </div>',

                // Managers
                '    <div class="dist-field">',
                '      <label class="dist-label">Ответственные менеджеры</label>',
                '      <div class="js-managers-list dist-managers-list"></div>',
                '      <button type="button" class="js-add-manager dist-btn dist-btn--secondary dist-btn--sm">+ Добавить менеджера</button>',
                '    </div>',

                // Checkboxes
                '    <div class="dist-row-2col">',
                '      <div class="dist-field">',
                '        <label class="dist-label">',
                '          <input type="checkbox" class="js-check-history" ' + (rule.check_history ? 'checked' : '') + ' />',
                '          Учитывать историю контакта/компании',
                '        </label>',
                '      </div>',
                '      <div class="dist-field">',
                '        <label class="dist-label">',
                '          <input type="checkbox" class="js-check-schedule" ' + (rule.check_schedule ? 'checked' : '') + ' />',
                '          Учитывать рабочее расписание',
                '        </label>',
                '      </div>',
                '    </div>',

                // Filters collapsible section
                '    <div class="dist-filters-section">',
                '      <button type="button" class="js-toggle-filters dist-toggle-btn">',
                '        <span class="dist-toggle-icon">&#9656;</span> Фильтры сделок',
                '      </button>',
                '      <div class="js-filters-body dist-filters-body" style="display:none;">',
                '        <div class="dist-row-2col">',
                '          <div class="dist-field">',
                '            <label class="dist-label">Бюджет от (₽)</label>',
                '            <input type="number" class="js-filter-budget-min dist-input" min="0" placeholder="0" value="' + (f.budget_min || '') + '" />',
                '          </div>',
                '          <div class="dist-field">',
                '            <label class="dist-label">Бюджет до (₽)</label>',
                '            <input type="number" class="js-filter-budget-max dist-input" min="0" placeholder="без ограничений" value="' + (f.budget_max || '') + '" />',
                '          </div>',
                '        </div>',
                '        <div class="dist-field">',
                '          <label class="dist-label">Название содержит</label>',
                '          <input type="text" class="js-filter-name dist-input" placeholder="например: доставка" value="' + _.escape(f.name_contains || '') + '" />',
                '        </div>',
                '        <div class="dist-field">',
                '          <label class="dist-label">Теги (через запятую)</label>',
                '          <input type="text" class="js-filter-tags dist-input" placeholder="vip, wholesale" value="' + _.escape((f.tags || []).join(', ')) + '" />',
                '          <small class="dist-hint">Сделка должна содержать ВСЕ указанные теги.</small>',
                '        </div>',
                '        <div class="dist-field">',
                '          <label class="dist-label">Дополнительные поля</label>',
                '          <div class="js-cf-list dist-cf-list"></div>',
                '          <button type="button" class="js-add-cf dist-btn dist-btn--secondary dist-btn--sm">+ Добавить условие</button>',
                '        </div>',
                '      </div>',
                '    </div>',

                '  </div>',
                '</div>'
            ].join('');
        }

        function bindFilterEvents($row, filters) {
            // Toggle filters section
            $row.on('click', '.js-toggle-filters', function() {
                var $body = $row.find('.js-filters-body');
                var $icon = $(this).find('.dist-toggle-icon');
                $body.toggle();
                $icon.html($body.is(':visible') ? '&#9662;' : '&#9656;');
            });

            // Show filters panel if any filter is already set
            var f = filters || {};
            if (f.budget_min || f.budget_max || f.name_contains || (f.tags && f.tags.length) || (f.custom_fields && f.custom_fields.length)) {
                $row.find('.js-filters-body').show();
                $row.find('.dist-toggle-icon').html('&#9662;');
            }

            // Add custom field condition
            $row.on('click', '.js-add-cf', function() {
                addCfRow($row, {});
            });
            $row.on('click', '.js-remove-cf', function() {
                $(this).closest('.dist-cf-row').remove();
            });

            // Render existing custom field conditions
            _.each(f.custom_fields || [], function(cf) {
                addCfRow($row, cf);
            });
        }

        function addCfRow($row, cf) {
            var html = [
                '<div class="dist-cf-row">',
                '  <input type="number" class="js-cf-field-id dist-input dist-input--sm" placeholder="ID поля" value="' + (cf.field_id || '') + '" />',
                '  <select class="js-cf-operator dist-select dist-select--sm">',
                '    <option value="eq"'       + (cf.operator === 'eq'       ? ' selected' : '') + '>равно</option>',
                '    <option value="contains"' + (cf.operator === 'contains' ? ' selected' : '') + '>содержит</option>',
                '    <option value="gte"'      + (cf.operator === 'gte'      ? ' selected' : '') + '>≥</option>',
                '    <option value="lte"'      + (cf.operator === 'lte'      ? ' selected' : '') + '>≤</option>',
                '  </select>',
                '  <input type="text" class="js-cf-value dist-input dist-input--sm" placeholder="значение" value="' + _.escape(cf.value || '') + '" />',
                '  <button type="button" class="js-remove-cf dist-btn dist-btn--danger dist-btn--sm">&#x2715;</button>',
                '</div>'
            ].join('');
            $row.find('.js-cf-list').append(html);
        }

        function recalcRuleIndexes($container) {
            $container.find('.dist-rule-row .rule-number').each(function(i) {
                $(this).text('Правило ' + (i + 1));
            });
        }

        function loadPipelinesIntoRow($row, rule) {
            if (window.AMOCRM && AMOCRM.data && AMOCRM.data.pipelines) {
                var pipelines = AMOCRM.data.pipelines;
                var $select   = $row.find('.js-rule-pipeline');

                _.each(pipelines, function(pipeline) {
                    var option = $('<option>', {
                        value:    pipeline.id,
                        text:     pipeline.name,
                        selected: rule.pipeline_id && String(rule.pipeline_id) === String(pipeline.id)
                    });
                    $select.append(option);
                });

                if (rule.pipeline_id) {
                    loadStages(rule.pipeline_id, $row, rule.stage_id);
                }
            }

            // managers
            _.each(rule.managers || [], function(manager) {
                addManagerRow($row, manager);
            });

            $row.on('click', '.js-add-manager', function() {
                addManagerRow($row, {});
            });

            $row.on('click', '.js-remove-manager', function() {
                $(this).closest('.dist-manager-row').remove();
            });

            // filters
            bindFilterEvents($row, rule.filters || {});
        }

        function loadStages(pipelineId, $row, selectedStageId) {
            var $stageSelect = $row.find('.js-rule-stage');
            $stageSelect.empty().append('<option value="">Любой</option>');

            if (!pipelineId) return;

            var pipelines = (AMOCRM.data && AMOCRM.data.pipelines) || [];
            var pipeline  = _.find(pipelines, function(p) { return String(p.id) === String(pipelineId); });
            if (!pipeline || !pipeline.statuses) return;

            _.each(pipeline.statuses, function(status) {
                if (status.type === 0) return; // skip system statuses
                $stageSelect.append($('<option>', {
                    value:    status.id,
                    text:     status.name,
                    selected: selectedStageId && String(selectedStageId) === String(status.id)
                }));
            });
        }

        function addManagerRow($row, manager) {
            var $list = $row.find('.js-managers-list');
            var users = getUsers();

            var options = _.map(users, function(user) {
                var sel = manager.id && String(manager.id) === String(user.id) ? ' selected' : '';
                return '<option value="' + user.id + '"' + sel + '>' + _.escape(user.name) + '</option>';
            }).join('');

            var html = [
                '<div class="dist-manager-row">',
                '  <select class="js-manager-select dist-select dist-select--inline" name="manager_id">',
                '    <option value="">— выберите —</option>',
                     options,
                '  </select>',
                '  <button type="button" class="js-remove-manager dist-btn dist-btn--danger dist-btn--sm">&#x2715;</button>',
                '</div>'
            ].join('');

            $list.append(html);
        }

        // ─── Collect settings from UI before save ─────────────────────────────
        function collectRules($container) {
            var rules = [];

            $container.find('.dist-rule-row').each(function() {
                var $row = $(this);

                // Managers
                var managers = [];
                $row.find('.dist-manager-row').each(function() {
                    var id = $(this).find('.js-manager-select').val();
                    if (id) managers.push({ id: id });
                });

                // Filters
                var filters = {};
                var budgetMin = $.trim($row.find('.js-filter-budget-min').val());
                var budgetMax = $.trim($row.find('.js-filter-budget-max').val());
                var nameContains = $.trim($row.find('.js-filter-name').val());
                var tagsRaw = $.trim($row.find('.js-filter-tags').val());

                if (budgetMin !== '')   filters.budget_min    = parseInt(budgetMin, 10);
                if (budgetMax !== '')   filters.budget_max    = parseInt(budgetMax, 10);
                if (nameContains)       filters.name_contains = nameContains;
                if (tagsRaw) {
                    filters.tags = _.compact(_.map(tagsRaw.split(','), function(t) {
                        return $.trim(t);
                    }));
                }

                var customFields = [];
                $row.find('.dist-cf-row').each(function() {
                    var fieldId  = $.trim($(this).find('.js-cf-field-id').val());
                    var operator = $(this).find('.js-cf-operator').val();
                    var value    = $.trim($(this).find('.js-cf-value').val());
                    if (fieldId && value) {
                        customFields.push({ field_id: parseInt(fieldId, 10), operator: operator, value: value });
                    }
                });
                if (customFields.length) filters.custom_fields = customFields;

                var rule = {
                    pipeline_id:    $row.find('.js-rule-pipeline').val() || null,
                    stage_id:       $row.find('.js-rule-stage').val()    || null,
                    check_history:  $row.find('.js-check-history').is(':checked'),
                    check_schedule: $row.find('.js-check-schedule').is(':checked'),
                    managers:       managers,
                    filters:        filters
                };

                if (rule.pipeline_id && rule.managers.length) {
                    rules.push(rule);
                }
            });

            return rules;
        }

        // ─── Digital Pipeline ─────────────────────────────────────────────────
        function handleDpEvent(eventData, dpSettings) {
            var settings = getSettings();
            var leadId   = eventData.lead && eventData.lead.id;

            if (!leadId) return;

            apiRequest('/api/distribute', {
                account_id:          getAccountId(),
                lead_id:             leadId,
                pipeline_id:         eventData.pipeline_id || null,
                stage_id:            eventData.lead_status_id || null,
                distribution_method: settings.distribution_method,
                rules:               settings.rules || [],
                dp_settings:         dpSettings || {}
            }).done(function(response) {
                if (response && response.user_id) {
                    var users   = getUsers();
                    var user    = _.find(users, function(u) { return String(u.id) === String(response.user_id); });
                    var name    = user ? user.name : '#' + response.user_id;
                    notify('Сделка #' + leadId + ' назначена на: ' + name, 'success');
                }
            }).fail(function(xhr) {
                console.error('[DealDist] Distribution failed', xhr);
                notify('Ошибка распределения сделки #' + leadId, 'error');
            });
        }

        // ═════════════════════════════════════════════════════════════════════
        //   AmoCRM Widget API methods
        // ═════════════════════════════════════════════════════════════════════

        var $settingsContainer = null;

        /** Called when widget renders anywhere */
        self.render = function() {
            return true;
        };

        /** Called once during initialization */
        self.init = function() {
            injectCss();
            return true;
        };

        // CSS не подключается автоматически — вставляем <link> на наш файл.
        function injectCss() {
            try {
                if (document.querySelector('link[data-dist-css]')) return;
                var base = (self.params && self.params.path) ? self.params.path : '';
                if (!base) return;
                var link = document.createElement('link');
                link.rel  = 'stylesheet';
                link.href = base.replace(/\/$/, '') + '/css/widget.css';
                link.setAttribute('data-dist-css', '1');
                document.head.appendChild(link);
            } catch (e) {}
        }

        /** Required by AmoCRM — bind UI events after render */
        self.bind = function() {
            return true;
        };

        /** Bind UI events after render */
        self.bind_actions = function() {
            return true;
        };

        /** Render settings page */
        self.settings = function($container) {
            injectCss();
            if (!$container) return false;
            $settingsContainer = $container;

            var settings = getSettings();

            $container.html([
                '<div class="dist-settings">',

                // ── Tab navigation ──────────────────────────────────────────
                '  <div class="dist-tabs">',
                '    <button type="button" class="dist-tab dist-tab--active" data-tab="status">Сотрудники</button>',
                '    <button type="button" class="dist-tab" data-tab="rules">Правила</button>',
                '    <button type="button" class="dist-tab" data-tab="schedules">Расписания</button>',
                '    <button type="button" class="dist-tab" data-tab="log">История</button>',
                '  </div>',

                // ── Tab: Сотрудники (кто участвует в распределении) ─────────
                '  <div class="dist-tab-panel" data-panel="status">',
                '    <div class="dist-section">',
                '      <div class="dist-log-toolbar">',
                '        <h4 class="dist-section__title" style="margin:0;">Сотрудники в распределении</h4>',
                '        <button type="button" class="js-refresh-status dist-btn dist-btn--secondary dist-btn--sm">&#x21bb; Обновить</button>',
                '      </div>',
                '      <p class="dist-hint">Тумблер справа задаёт, участвует ли сотрудник в распределении. Выключенным сделки не назначаются. Список берётся из пользователей amoCRM.</p>',
                '      <div class="js-status-body dist-status-body"><p class="dist-hint">Загрузка...</p></div>',
                '    </div>',
                '  </div>',

                // ── Tab: Rules ──────────────────────────────────────────────
                '  <div class="dist-tab-panel" data-panel="rules" style="display:none;">',

                '    <div class="dist-section">',
                '      <div class="dist-field">',
                '        <label class="dist-label">URL сервера <span class="dist-required">*</span></label>',
                '        <input type="text" class="js-server-url dist-input" placeholder="https://your-server.com" value="' + _.escape(settings.server_url || '') + '" />',
                '        <small class="dist-hint">Адрес бэкенд-сервиса, обрабатывающего распределение сделок.</small>',
                '      </div>',
                '      <div class="dist-field">',
                '        <label class="dist-label">Метод распределения</label>',
                '        <select class="js-dist-method dist-select">',
                '          <option value="round_robin"' + (settings.distribution_method === 'round_robin' ? ' selected' : '') + '>Round Robin (по очереди)</option>',
                '          <option value="workload"'   + (settings.distribution_method === 'workload'    ? ' selected' : '') + '>По загруженности</option>',
                '        </select>',
                '      </div>',
                '    </div>',

                '    <div class="dist-section">',
                '      <h4 class="dist-section__title">Правила распределения</h4>',
                '      <p class="dist-hint">Каждое правило задаёт, на каком этапе воронки и каким менеджерам назначать сделки.</p>',
                '      <div class="js-rules-list dist-rules-list"></div>',
                '      <button type="button" class="js-add-rule dist-btn dist-btn--primary">+ Добавить правило</button>',
                '    </div>',

                '  </div>',

                // ── Tab: Schedules ──────────────────────────────────────────
                '  <div class="dist-tab-panel" data-panel="schedules" style="display:none;">',
                '    <div class="dist-section">',
                '      <h4 class="dist-section__title">Рабочие расписания менеджеров</h4>',
                '      <p class="dist-hint">Задайте рабочие часы для каждого менеджера. Сделки не будут назначаться в нерабочее время (если включена соответствующая опция в правиле).</p>',
                '      <div class="js-schedules-list dist-schedules-list"></div>',
                '      <div class="dist-schedule-add-row">',
                '        <select class="js-schedule-user-select dist-select dist-select--inline">',
                '          <option value="">— выберите менеджера —</option>',
                '        </select>',
                '        <button type="button" class="js-add-schedule dist-btn dist-btn--secondary">+ Добавить расписание</button>',
                '      </div>',
                '    </div>',
                '  </div>',

                // ── Tab: Log ────────────────────────────────────────────────
                '  <div class="dist-tab-panel" data-panel="log" style="display:none;">',
                '    <div class="dist-section">',
                '      <div class="dist-log-toolbar">',
                '        <h4 class="dist-section__title" style="margin:0;">История распределений</h4>',
                '        <button type="button" class="js-refresh-log dist-btn dist-btn--secondary dist-btn--sm">&#x21bb; Обновить</button>',
                '      </div>',
                '      <div class="js-log-body dist-log-body">',
                '        <p class="dist-hint">Нажмите «Обновить» для загрузки истории.</p>',
                '      </div>',
                '    </div>',
                '  </div>',

                '</div>'
            ].join(''));

            // ── Tab switching ───────────────────────────────────────────────
            $container.on('click', '.dist-tab', function() {
                var tab = $(this).data('tab');
                $container.find('.dist-tab').removeClass('dist-tab--active');
                $(this).addClass('dist-tab--active');
                $container.find('.dist-tab-panel').hide();
                $container.find('[data-panel="' + tab + '"]').show();

                if (tab === 'status')    renderStatusTab($container);
                if (tab === 'schedules') renderSchedulesTab($container);
                if (tab === 'log')       renderLogTab($container);
            });

            // Тумблер «в распределении / вне» прямо в настройках.
            $container.on('change', '.js-mgr-online', function() {
                var $i = $(this);
                setStatus($i.data('uid'), $i.prop('checked'), $i);
            });
            $container.on('click', '.js-refresh-status', function() {
                renderStatusTab($container);
            });

            bindSettingsEvents($container, settings);

            // Вкладка «Сотрудники» открыта по умолчанию — грузим сразу.
            renderStatusTab($container);

            return true;
        };

        // Ростер сотрудников с тумблерами участия — версия для панели settings.
        function renderStatusTab($container) {
            var $body = $container.find('.js-status-body');
            if (!$body.length) return;
            var users = getUsers();
            if (!users.length) {
                $body.html(emptyState(self.i18n('status.no_users')));
                return;
            }
            $body.html('<p class="dist-hint">' + _.escape(self.i18n('common.loading')) + '</p>');
            apiRequest('/api/status', null, 'GET')
                .done(function(resp) {
                    renderStatusTable($body, users, (resp && resp.statuses) || {});
                })
                .fail(function() {
                    renderStatusTable($body, users, {});
                    notify(self.i18n('status.load_error'), 'error');
                });
        }

        // ═════════════════════════════════════════════════════════════════════
        //   Advanced settings — полностраничный виджет (сайдбар + модули)
        // ═════════════════════════════════════════════════════════════════════

        function getUsers() {
            var users = [];
            try {
                var mgr = (window.AMOCRM && AMOCRM.constant && AMOCRM.constant('managers')) || null;
                if (mgr && typeof mgr === 'object') {
                    _.each(mgr, function(u) {
                        if (!u || u.id == null) return;
                        users.push({
                            id:    parseInt(u.id, 10),
                            name:  u.option || u.name || ('#' + u.id),
                            group: u.group_name || u.group || ''
                        });
                    });
                }
            } catch (e) {}
            if (!users.length && window.AMOCRM && AMOCRM.data && AMOCRM.data.users) {
                _.each(AMOCRM.data.users, function(u) {
                    users.push({ id: parseInt(u.id, 10), name: u.name || ('#' + u.id), group: '' });
                });
            }
            return users;
        }

        function currentUserId() {
            try {
                var u = AMOCRM.constant('user');
                return (u && u.id) ? parseInt(u.id, 10) : null;
            } catch (e) { return null; }
        }

        function initials(name) {
            var parts = $.trim(name).split(/\s+/);
            var s = (parts[0] || '').charAt(0) + (parts[1] || '').charAt(0);
            return (s || '?').toUpperCase();
        }

        function avatarColor(id) {
            var palette = ['#d22730', '#12915a', '#c47812', '#7b3fce', '#2b7de9', '#d34580', '#0f8a8a'];
            return palette[Math.abs(parseInt(id, 10) || 0) % palette.length];
        }

        self.advancedSettings = function() {
            injectCss();
            var titleText = $.trim(self.i18n('advanced.title') || 'Распределение сделок');
            var $title = $();
            $('h1, h2, h3').each(function() {
                if ($title.length) return;
                if ($.trim($(this).text()) === titleText) $title = $(this);
            });

            var $mount;
            if ($title.length) {
                $mount = $('<div class="dist-adv"></div>');
                $title.after($mount);
            } else {
                $mount = $('<div class="dist-adv dist-adv--wide"></div>');
                var $area = $('.widget_advanced_settings, .list-pipelines__hidden').first();
                ($area.length ? $area : $(document.body)).append($mount);
            }
            renderAdvancedPage($mount);
            return true;
        };

        // Страница из левого меню (widget_page). Контейнер #work-area-<code>
        // amoCRM создаёт асинхронно — ищем с повтором, иначе страница пустая.
        self.initMenuPage = function(params) {
            injectCss();
            var attempts = 0;
            function findArea() {
                var code = (self.params && self.params.widget_code) ||
                           (getSettings().widget_code) || '';
                var $a = code ? $('#work-area-' + code) : $();
                if (!$a.length) $a = $('div[id^="work-area-"]:visible').first();
                if (!$a.length) $a = $('div[id^="work-area-"]').first();
                if (!$a.length) $a = $('.work-area, #work-area').first();
                return $a;
            }
            function mount() {
                var $area = findArea();
                if (!$area.length) {
                    if (attempts++ < 25) { setTimeout(mount, 120); return; }
                    $area = $('.easy-element, #page_holder .content, #content').first();
                    if (!$area.length) $area = $(document.body);
                }
                if ($area.find('.dist-adv').length) return; // уже отрисовано
                $area.empty();
                var $mount = $('<div class="dist-adv dist-adv--wide"></div>');
                $area.append($mount);
                renderAdvancedPage($mount);
            }
            mount();
            return true;
        };

        var $advMount = null;              // корневой контейнер advanced-страницы
        var tplCache  = {};                // id → шаблон (для редактирования)

        function navItem(key, label, on) {
            return '<button class="dist-nav__i' + (on ? ' dist-nav__i--on' : '') +
                   '" data-p="' + key + '">' + _.escape(label) + '</button>';
        }

        function panelHead(title, desc, $btn) {
            return '<div class="dist-main__head' + ($btn ? ' dist-head-row' : '') + '">' +
                   '<div><h2>' + _.escape(title) + '</h2><p>' + _.escape(desc) + '</p></div>' +
                   ($btn || '') + '</div>';
        }

        function renderAdvancedPage($mount) {
            $advMount = $mount;
            $mount.html([
                '<div class="dist-page">',
                '  <aside class="dist-side">',
                '    <div class="dist-side__brand">',
                '      <div class="dist-side__mk">',
                '        <svg viewBox="0 0 24 24" fill="none" width="22" height="22">',
                '          <path d="M6 12l11-6M6 12h11M6 12l11 6" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>',
                '          <circle cx="6" cy="12" r="2.4" fill="#fff"/>',
                '          <circle cx="18" cy="6" r="1.9" fill="#e5484d"/><circle cx="18" cy="12" r="1.9" fill="#e5484d"/><circle cx="18" cy="18" r="1.9" fill="#e5484d"/>',
                '        </svg>',
                '      </div>',
                '      <div><b>KO:AGENCY</b><span>РАСПРЕДЕЛЕНИЕ</span></div>',
                '    </div>',
                '    <div class="dist-online">',
                '      <div class="dist-online__row">',
                '        <span class="dist-online__lbl"><i class="dist-dot"></i>' + _.escape(self.i18n('status.online')) + '</span>',
                '        <label class="dist-switch"><input type="checkbox" class="js-self-online"><span class="dist-switch__tr"></span></label>',
                '      </div>',
                '      <small>' + _.escape(self.i18n('status.self_hint')) + '</small>',
                '    </div>',
                '    <nav class="dist-nav">',
                       navItem('status',    self.i18n('status.title'),   true),
                       navItem('templates', self.i18n('nav.templates'),  false),
                '      <button class="dist-nav__i" disabled>' + _.escape(self.i18n('nav.worktime')) + ' <span class="dist-soon">' + _.escape(self.i18n('common.soon')) + '</span></button>',
                '      <button class="dist-nav__i" disabled>' + _.escape(self.i18n('nav.reports')) + ' <span class="dist-soon">' + _.escape(self.i18n('common.soon')) + '</span></button>',
                '    </nav>',
                '    <div class="dist-side__foot">raspredelenie.koagency.ru</div>',
                '  </aside>',
                '  <main class="dist-main"><div class="js-panel"></div></main>',
                '</div>'
            ].join(''));

            bindAdvancedEvents($mount);
            switchPanel($mount, 'status');
        }

        function switchPanel($mount, key) {
            $mount.find('.dist-nav__i').removeClass('dist-nav__i--on');
            $mount.find('.dist-nav__i[data-p="' + key + '"]').addClass('dist-nav__i--on');
            var $panel = $mount.find('.js-panel');
            if (key === 'templates') renderTemplatesPanel($mount, $panel);
            else                     renderStatusPanel($mount, $panel);
        }

        // ── Панель: Статусы ─────────────────────────────────────────────────────
        function renderStatusPanel($mount, $panel) {
            var refreshBtn = '<button class="dist-btn dist-btn--secondary dist-btn--sm js-status-refresh">&#x21bb; ' +
                             _.escape(self.i18n('common.refresh')) + '</button>';
            $panel.html(
                panelHead(self.i18n('status.title'), self.i18n('status.desc'), refreshBtn) +
                '<div class="dist-status-count dist-muted js-active-count"></div>' +
                '<div class="dist-card"><div class="js-status-body dist-status-body"><p class="dist-muted">' +
                _.escape(self.i18n('common.loading')) + '</p></div></div>'
            );

            var users = getUsers();
            var $body = $panel.find('.js-status-body');
            if (!users.length) {
                $body.html(emptyState(self.i18n('status.no_users')));
                return;
            }
            apiRequest('/api/status', null, 'GET')
                .done(function(resp) {
                    var statuses = (resp && resp.statuses) || {};
                    renderStatusTable($body, users, statuses);
                    syncSelfToggle($mount, statuses);
                    updateActiveCount($mount);
                })
                .fail(function() {
                    renderStatusTable($body, users, {});
                    updateActiveCount($mount);
                    notify(self.i18n('status.load_error'), 'error');
                });
        }

        // Счётчик «сколько сотрудников участвует» — обновляем из текущих тумблеров,
        // чтобы значение не зависело от формата ответа сервера.
        function updateActiveCount($scope) {
            $scope = $scope || $advMount;
            if (!$scope || !$scope.length) return;
            var total = $scope.find('.js-mgr-online').length;
            var on    = $scope.find('.js-mgr-online:checked').length;
            $scope.find('.js-active-count').text(
                self.i18n('status.active').replace('{n}', on).replace('{total}', total)
            );
        }

        // ── Панель: Шаблоны ─────────────────────────────────────────────────────
        function tplTypeLabel(type) {
            return self.i18n('templates.type_' + type) || type;
        }

        function renderTemplatesPanel($mount, $panel) {
            var addBtn = '<button class="dist-btn dist-btn--primary js-tpl-add">' +
                         _.escape(self.i18n('templates.add')) + '</button>';
            $panel.html(
                panelHead(self.i18n('templates.title'), self.i18n('templates.desc'), addBtn) +
                '<div class="dist-card"><div class="js-tpl-body"><p class="dist-muted">' +
                _.escape(self.i18n('common.loading')) + '</p></div></div>'
            );
            loadTemplates($panel);
        }

        function loadTemplates($panel) {
            var $body = $panel.find('.js-tpl-body');
            apiRequest('/api/templates', null, 'GET')
                .done(function(resp) { renderTemplatesTable($body, (resp && resp.templates) || []); })
                .fail(function() { $body.html(errorState(self.i18n('templates.load_error'))); });
        }

        function renderTemplatesTable($body, templates) {
            tplCache = {};
            if (!templates.length) {
                $body.html(emptyState(self.i18n('templates.empty')));
                return;
            }
            var html = ['<table class="dist-table"><thead><tr>' +
                '<th>' + _.escape(self.i18n('templates.col_name')) + '</th>' +
                '<th>' + _.escape(self.i18n('templates.col_type')) + '</th>' +
                '<th class="dist-ta-c">' + _.escape(self.i18n('templates.col_managers')) + '</th>' +
                '<th class="dist-ta-r"></th></tr></thead><tbody>'];

            _.each(templates, function(t) {
                tplCache[t.id] = t;
                html.push(
                    '<tr>' +
                    '<td><b>' + _.escape(t.name) + '</b></td>' +
                    '<td><span class="dist-badge">' + _.escape(tplTypeLabel(t.type)) + '</span></td>' +
                    '<td class="dist-ta-c dist-mono">' + ((t.managers || []).length) + '</td>' +
                    '<td class="dist-ta-r dist-row-actions">' +
                    '<button class="dist-icon-btn js-tpl-edit" data-id="' + _.escape(t.id) + '" title="' + _.escape(self.i18n('common.edit')) + '">&#9998;</button>' +
                    '<button class="dist-icon-btn dist-icon-btn--danger js-tpl-del" data-id="' + _.escape(t.id) + '" title="' + _.escape(self.i18n('common.delete')) + '">&#215;</button>' +
                    '</td></tr>'
                );
            });
            html.push('</tbody></table>');
            $body.html(html.join(''));
        }

        // ── Модалка шаблона ─────────────────────────────────────────────────────
        function openTemplateModal(tpl) {
            var isEdit = !!tpl;
            tpl = tpl || { name: '', type: 'round_robin', managers: [], check_history: false, check_schedule: false };
            var users = getUsers();

            var seg = _.map(['round_robin', 'workload', 'percent'], function(tp) {
                return '<button type="button" class="dist-seg__i' + (tpl.type === tp ? ' dist-seg__i--on' : '') +
                       '" data-type="' + tp + '">' + _.escape(tplTypeLabel(tp)) + '</button>';
            }).join('');

            var $modal = $(
                '<div class="dist-modal">' +
                '  <div class="dist-modal__box">' +
                '    <div class="dist-modal__head"><b>' +
                       _.escape(self.i18n(isEdit ? 'templates.modal_edit' : 'templates.modal_new')) +
                '      </b><button class="dist-modal__x js-modal-close">&#215;</button></div>' +
                '    <div class="dist-modal__body">' +
                '      <div class="dist-field"><label class="dist-label">' + _.escape(self.i18n('templates.f_name')) +
                         ' <span class="dist-required">*</span></label>' +
                '        <input type="text" class="dist-input js-tpl-name" value="' + _.escape(tpl.name) + '" /></div>' +
                '      <div class="dist-field"><label class="dist-label">' + _.escape(self.i18n('templates.f_type')) + '</label>' +
                '        <div class="dist-seg js-tpl-seg">' + seg + '</div></div>' +
                '      <div class="dist-field"><label class="dist-label">' + _.escape(self.i18n('templates.f_managers')) + '</label>' +
                '        <div class="js-tpl-chips dist-chips"></div>' +
                '        <select class="dist-select js-tpl-mgr-add"><option value="">' + _.escape(self.i18n('templates.add_manager')) + '</option>' +
                           _.map(users, function(u) { return '<option value="' + u.id + '">' + _.escape(u.name) + '</option>'; }).join('') +
                '        </select></div>' +
                '      <label class="dist-check"><input type="checkbox" class="js-tpl-history"' + (tpl.check_history ? ' checked' : '') + '> ' +
                         _.escape(self.i18n('templates.f_history')) + '</label>' +
                '      <label class="dist-check"><input type="checkbox" class="js-tpl-schedule"' + (tpl.check_schedule ? ' checked' : '') + '> ' +
                         _.escape(self.i18n('templates.f_schedule')) + '</label>' +
                '    </div>' +
                '    <div class="dist-modal__foot">' +
                '      <button class="dist-btn dist-btn--secondary js-modal-close">' + _.escape(self.i18n('common.cancel')) + '</button>' +
                '      <button class="dist-btn dist-btn--primary js-tpl-save" data-id="' + _.escape(tpl.id || '') + '">' +
                         _.escape(self.i18n('common.save')) + '</button>' +
                '    </div>' +
                '  </div></div>'
            );

            $advMount.append($modal);
            $modal.data('type', tpl.type);
            var $chips = $modal.find('.js-tpl-chips');
            _.each(tpl.managers || [], function(m) {
                var u = _.find(users, function(x) { return String(x.id) === String(m.id); });
                addChip($chips, m.id, u ? u.name : ('#' + m.id), m.percent, tpl.type === 'percent');
            });
        }

        function addChip($chips, id, name, percent, showPercent) {
            if ($chips.find('.dist-chip[data-uid="' + id + '"]').length) return;
            var pct = showPercent
                ? '<input type="number" class="dist-chip__pct js-chip-pct" min="0" max="100" value="' + (percent != null ? percent : 0) + '">%'
                : '';
            $chips.append(
                '<span class="dist-chip" data-uid="' + id + '">' +
                '<span class="dist-av dist-av--sm" style="background:' + avatarColor(id) + '">' + _.escape(initials(name)) + '</span>' +
                _.escape(name) + pct +
                '<span class="dist-chip__x js-chip-x">&#215;</span></span>'
            );
        }

        function saveTemplate($modal) {
            var id   = $modal.find('.js-tpl-save').data('id');
            var type = $modal.data('type') || 'round_robin';
            var name = $.trim($modal.find('.js-tpl-name').val());
            if (!name) { notify(self.i18n('templates.name_required'), 'error'); return; }

            var managers = [];
            $modal.find('.dist-chip').each(function() {
                var entry = { id: parseInt($(this).data('uid'), 10) };
                if (type === 'percent') entry.percent = parseInt($(this).find('.js-chip-pct').val(), 10) || 0;
                managers.push(entry);
            });

            var payload = {
                name:           name,
                type:           type,
                managers:       managers,
                check_history:  $modal.find('.js-tpl-history').prop('checked'),
                check_schedule: $modal.find('.js-tpl-schedule').prop('checked')
            };

            var req = id
                ? apiRequest('/api/templates/' + id, payload, 'PUT')
                : apiRequest('/api/templates', payload, 'POST');

            $modal.find('.js-tpl-save').prop('disabled', true).text(self.i18n('common.saving'));
            req.done(function() {
                $modal.remove();
                loadTemplates($advMount.find('.js-panel'));
            }).fail(function() {
                $modal.find('.js-tpl-save').prop('disabled', false).text(self.i18n('common.save'));
                notify(self.i18n('templates.save_error'), 'error');
            });
        }

        function deleteTemplate(id) {
            apiRequest('/api/templates/' + id, null, 'DELETE')
                .done(function() { loadTemplates($advMount.find('.js-panel')); })
                .fail(function() { notify(self.i18n('templates.save_error'), 'error'); });
        }

        function emptyState(text) {
            return '<div class="dist-empty-box"><div class="dist-empty-ic">&#9711;</div><p>' + _.escape(text) + '</p></div>';
        }
        function errorState(text) {
            return '<div class="dist-empty-box dist-empty-box--err"><div class="dist-empty-ic">!</div><p>' + _.escape(text) + '</p></div>';
        }

        function renderStatusTable($body, users, statuses) {
            var groups = {};
            _.each(users, function(u) {
                var g = u.group || '—';
                (groups[g] = groups[g] || []).push(u);
            });

            var html = ['<table class="dist-table"><thead><tr><th>' + _.escape(self.i18n('status.col_manager')) +
                        '</th><th class="dist-ta-r">' + _.escape(self.i18n('status.col_status')) + '</th></tr></thead><tbody>'];

            _.each(groups, function(list, group) {
                if (group !== '—') {
                    html.push('<tr class="dist-tr-group"><td colspan="2">' + _.escape(group) + '</td></tr>');
                }
                _.each(list, function(u) {
                    var online = !Object.prototype.hasOwnProperty.call(statuses, String(u.id)) ||
                                 !!(statuses[String(u.id)] && statuses[String(u.id)].online);
                    html.push(
                        '<tr>' +
                        '<td><span class="dist-u"><span class="dist-av" style="background:' + avatarColor(u.id) + '">' +
                        _.escape(initials(u.name)) + '</span>' + _.escape(u.name) + '</span></td>' +
                        '<td class="dist-ta-r">' +
                        '<label class="dist-switch"><input type="checkbox" class="js-mgr-online" data-uid="' + u.id + '"' +
                        (online ? ' checked' : '') + '><span class="dist-switch__tr"></span></label>' +
                        '</td></tr>'
                    );
                });
            });

            html.push('</tbody></table>');
            $body.html(html.join(''));
        }

        function syncSelfToggle($mount, statuses) {
            var uid = currentUserId();
            if (uid == null) { $mount.find('.js-self-online').closest('.dist-online').hide(); return; }
            var online = !Object.prototype.hasOwnProperty.call(statuses, String(uid)) ||
                         !!(statuses[String(uid)] && statuses[String(uid)].online);
            $mount.find('.js-self-online').prop('checked', online);
        }

        function setStatus(userId, online, $input) {
            var uid = currentUserId();
            apiRequest('/api/status/' + parseInt(userId, 10), { online: !!online, actor_id: uid }, 'PUT')
                .done(function() {
                    notify(self.i18n('status.saved'), 'success'); // тумблеры сохраняются сразу
                    updateActiveCount();
                })
                .fail(function() {
                    if ($input) $input.prop('checked', !online); // откат
                    notify(self.i18n('status.save_error'), 'error');
                });
        }

        function bindAdvancedEvents($mount) {
            // Неймспейс .distadv защищает от дублей при пересоздании (init_once:false).
            $mount
                .off('.distadv')
                // ── навигация по сайдбару ──
                .on('click.distadv', '.dist-nav__i[data-p]', function() {
                    switchPanel($mount, $(this).data('p'));
                })
                // ── обновить ростер сотрудников ──
                .on('click.distadv', '.js-status-refresh', function() {
                    switchPanel($mount, 'status');
                })
                // ── статусы ──
                .on('change.distadv', '.js-mgr-online', function() {
                    var $i = $(this);
                    setStatus($i.data('uid'), $i.prop('checked'), $i);
                })
                .on('change.distadv', '.js-self-online', function() {
                    var $i  = $(this);
                    var uid = currentUserId();
                    if (uid == null) return;
                    setStatus(uid, $i.prop('checked'), $i);
                    $mount.find('.js-mgr-online[data-uid="' + uid + '"]').prop('checked', $i.prop('checked'));
                })
                // ── шаблоны: список ──
                .on('click.distadv', '.js-tpl-add', function() { openTemplateModal(null); })
                .on('click.distadv', '.js-tpl-edit', function() { openTemplateModal(tplCache[$(this).data('id')]); })
                .on('click.distadv', '.js-tpl-del', function() {
                    if (window.confirm(self.i18n('templates.confirm_delete'))) deleteTemplate($(this).data('id'));
                })
                // ── шаблоны: модалка ──
                .on('click.distadv', '.js-modal-close', function() { $(this).closest('.dist-modal').remove(); })
                .on('click.distadv', '.js-tpl-seg .dist-seg__i', function() {
                    var $modal = $(this).closest('.dist-modal');
                    var type   = $(this).data('type');
                    $modal.data('type', type);
                    $modal.find('.dist-seg__i').removeClass('dist-seg__i--on');
                    $(this).addClass('dist-seg__i--on');
                    // Показать/убрать поля процентов у чипов.
                    var isPct = (type === 'percent');
                    $modal.find('.dist-chip').each(function() {
                        var $c = $(this);
                        if (isPct && !$c.find('.js-chip-pct').length) {
                            $c.find('.dist-chip__x').before('<input type="number" class="dist-chip__pct js-chip-pct" min="0" max="100" value="0">%');
                        } else if (!isPct) {
                            $c.find('.js-chip-pct').remove();
                            $c.find('span:contains("%")');
                        }
                    });
                    if (!isPct) $modal.find('.dist-chip').contents().filter(function(){ return this.nodeType===3 && this.nodeValue==='%'; }).remove();
                })
                .on('change.distadv', '.js-tpl-mgr-add', function() {
                    var uid = $(this).val();
                    if (!uid) return;
                    var $modal = $(this).closest('.dist-modal');
                    var name   = $(this).find('option:selected').text();
                    addChip($modal.find('.js-tpl-chips'), parseInt(uid, 10), name, 0, $modal.data('type') === 'percent');
                    $(this).val('');
                })
                .on('click.distadv', '.js-chip-x', function() { $(this).closest('.dist-chip').remove(); })
                .on('click.distadv', '.js-tpl-save', function() { saveTemplate($(this).closest('.dist-modal')); });
        }

        // ─── Schedules tab ────────────────────────────────────────────────────

        var DAY_LABELS = { mon: 'Пн', tue: 'Вт', wed: 'Ср', thu: 'Чт', fri: 'Пт', sat: 'Сб', sun: 'Вс' };
        var DAY_KEYS   = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        function renderSchedulesTab($container) {
            var $panel   = $container.find('[data-panel="schedules"]');
            var $list    = $panel.find('.js-schedules-list');
            var $select  = $panel.find('.js-schedule-user-select');

            // Populate user selector
            var users = getUsers();
            $select.empty().append('<option value="">— выберите менеджера —</option>');
            _.each(users, function(u) {
                $select.append('<option value="' + u.id + '">' + _.escape(u.name) + '</option>');
            });

            // Load existing schedules from backend
            $list.html('<p class="dist-hint">Загрузка...</p>');
            apiRequest('/api/schedules', null, 'GET').done(function(data) {
                $list.empty();
                if (!data || !Object.keys(data).length) {
                    $list.html('<p class="dist-hint dist-empty">Расписания не настроены.</p>');
                    return;
                }
                _.each(data, function(schedule, userId) {
                    var user = _.find(users, function(u) { return String(u.id) === String(userId); });
                    var name = user ? _.escape(user.name) : 'Менеджер #' + userId;
                    $list.append(buildScheduleCard(userId, name, schedule));
                });
                bindScheduleCardEvents($panel, users);
            }).fail(function() {
                $list.html('<p class="dist-hint">Ошибка загрузки расписаний.</p>');
            });

            // Add schedule
            $panel.off('click.sched', '.js-add-schedule').on('click.sched', '.js-add-schedule', function() {
                var userId = $select.val();
                if (!userId) return;
                var user   = _.find(users, function(u) { return String(u.id) === String(userId); });
                var name   = user ? _.escape(user.name) : 'Менеджер #' + userId;
                var defaultSched = {
                    timezone: 'Europe/Moscow',
                    days: {
                        mon: { start: '09:00', end: '18:00' }, tue: { start: '09:00', end: '18:00' },
                        wed: { start: '09:00', end: '18:00' }, thu: { start: '09:00', end: '18:00' },
                        fri: { start: '09:00', end: '18:00' }, sat: null, sun: null
                    }
                };
                if ($panel.find('.js-schedule-card[data-user-id="' + userId + '"]').length) return;
                $panel.find('.js-schedules-list').append(buildScheduleCard(userId, name, defaultSched));
                bindScheduleCardEvents($panel, users);
                $select.val('');
            });
        }

        function buildScheduleCard(userId, name, schedule) {
            var tz   = _.escape(schedule.timezone || 'Europe/Moscow');
            var days = _.map(DAY_KEYS, function(day) {
                var slot    = schedule.days ? schedule.days[day] : null;
                var isOff   = slot === null || slot === undefined;
                var start   = isOff ? '09:00' : (slot.start || '09:00');
                var end_    = isOff ? '18:00' : (slot.end   || '18:00');
                return [
                    '<div class="dist-day-row">',
                    '  <label class="dist-day-toggle">',
                    '    <input type="checkbox" class="js-day-toggle" data-day="' + day + '" ' + (isOff ? '' : 'checked') + ' />',
                    '    <span class="dist-day-label">' + DAY_LABELS[day] + '</span>',
                    '  </label>',
                    '  <div class="dist-day-slots ' + (isOff ? 'dist-day-slots--hidden' : '') + '" data-day="' + day + '">',
                    '    <input type="time" class="js-day-start dist-input-time" value="' + start + '" />',
                    '    <span class="dist-day-sep">—</span>',
                    '    <input type="time" class="js-day-end dist-input-time" value="' + end_ + '" />',
                    '  </div>',
                    '</div>'
                ].join('');
            }).join('');

            return [
                '<div class="dist-schedule-card js-schedule-card" data-user-id="' + userId + '">',
                '  <div class="dist-schedule-card__header">',
                '    <span class="dist-schedule-card__name">' + name + '</span>',
                '    <div class="dist-schedule-card__actions">',
                '      <button type="button" class="js-save-schedule dist-btn dist-btn--primary dist-btn--sm" data-user-id="' + userId + '">Сохранить</button>',
                '      <button type="button" class="js-delete-schedule dist-btn dist-btn--danger dist-btn--sm" data-user-id="' + userId + '">Удалить</button>',
                '    </div>',
                '  </div>',
                '  <div class="dist-schedule-card__body">',
                '    <div class="dist-field">',
                '      <label class="dist-label">Часовой пояс</label>',
                '      <input type="text" class="js-schedule-tz dist-input" value="' + tz + '" placeholder="Europe/Moscow" />',
                '    </div>',
                '    <div class="dist-days-grid">' + days + '</div>',
                '  </div>',
                '</div>'
            ].join('');
        }

        function bindScheduleCardEvents($panel, users) {
            // Day toggle
            $panel.off('change.sched', '.js-day-toggle').on('change.sched', '.js-day-toggle', function() {
                var day    = $(this).data('day');
                var $slots = $panel.find('.dist-day-slots[data-day="' + day + '"]').closest('.dist-schedule-card').find('.dist-day-slots[data-day="' + day + '"]');
                $slots.toggleClass('dist-day-slots--hidden', !$(this).is(':checked'));
            });

            // Save schedule
            $panel.off('click.sched-save', '.js-save-schedule').on('click.sched-save', '.js-save-schedule', function() {
                var userId = $(this).data('user-id');
                var $card  = $panel.find('.js-schedule-card[data-user-id="' + userId + '"]');
                var sched  = collectScheduleFromCard($card);

                apiRequest('/api/schedules/' + userId, sched, 'PUT').done(function() {
                    notify('Расписание сохранено', 'success');
                }).fail(function() {
                    notify('Ошибка сохранения расписания', 'error');
                });
            });

            // Delete schedule
            $panel.off('click.sched-del', '.js-delete-schedule').on('click.sched-del', '.js-delete-schedule', function() {
                var userId = $(this).data('user-id');
                var $card  = $panel.find('.js-schedule-card[data-user-id="' + userId + '"]');

                apiRequest('/api/schedules/' + userId, null, 'DELETE').done(function() {
                    $card.remove();
                    notify('Расписание удалено', 'success');
                }).fail(function() {
                    notify('Ошибка удаления расписания', 'error');
                });
            });
        }

        function collectScheduleFromCard($card) {
            var tz   = $.trim($card.find('.js-schedule-tz').val()) || 'Europe/Moscow';
            var days = {};
            _.each(DAY_KEYS, function(day) {
                var $toggle = $card.find('.js-day-toggle[data-day="' + day + '"]');
                if (!$toggle.is(':checked')) {
                    days[day] = null;
                } else {
                    var $slots = $card.find('.dist-day-slots[data-day="' + day + '"]');
                    days[day] = {
                        start: $slots.find('.js-day-start').val() || '09:00',
                        end:   $slots.find('.js-day-end').val()   || '18:00'
                    };
                }
            });
            return { timezone: tz, days: days };
        }

        // ─── Log tab ──────────────────────────────────────────────────────────

        var LOG_REASONS = {
            assigned:            'Назначена',
            skipped_no_rule:     'Нет правила',
            skipped_schedule:    'Вне расписания',
            skipped_no_managers: 'Нет менеджеров',
            history_match:       'История контакта'
        };

        function renderLogTab($container) {
            var $panel = $container.find('[data-panel="log"]');

            $panel.off('click.log', '.js-refresh-log').on('click.log', '.js-refresh-log', function() {
                loadLog($panel);
            });

            loadLog($panel);
        }

        function loadLog($panel) {
            var $body = $panel.find('.js-log-body');
            $body.html('<p class="dist-hint">Загрузка...</p>');

            apiRequest('/api/log?limit=100', null, 'GET').done(function(entries) {
                if (!entries || !entries.length) {
                    $body.html('<p class="dist-hint dist-empty">История пуста.</p>');
                    return;
                }

                var users = getUsers();

                var rows = _.map(entries, function(e) {
                    var user    = _.find(users, function(u) { return String(u.id) === String(e.manager_id); });
                    var manager = e.manager_id ? (user ? _.escape(user.name) : '#' + e.manager_id) : '—';
                    var reason  = LOG_REASONS[e.reason] || e.reason;
                    var date    = e.ts ? new Date(e.ts * 1000).toLocaleString('ru-RU') : '—';
                    var badge   = e.reason === 'assigned' || e.reason === 'history_match'
                        ? 'dist-badge--success' : 'dist-badge--muted';

                    return [
                        '<tr>',
                        '  <td class="dist-log-td">' + date + '</td>',
                        '  <td class="dist-log-td"><a href="/leads/detail/' + e.lead_id + '" target="_blank">#' + e.lead_id + '</a></td>',
                        '  <td class="dist-log-td">' + manager + '</td>',
                        '  <td class="dist-log-td"><span class="dist-badge ' + badge + '">' + reason + '</span></td>',
                        '</tr>'
                    ].join('');
                }).join('');

                $body.html([
                    '<table class="dist-log-table">',
                    '  <thead><tr>',
                    '    <th class="dist-log-th">Время</th>',
                    '    <th class="dist-log-th">Сделка</th>',
                    '    <th class="dist-log-th">Менеджер</th>',
                    '    <th class="dist-log-th">Результат</th>',
                    '  </tr></thead>',
                    '  <tbody>' + rows + '</tbody>',
                    '</table>'
                ].join(''));
            }).fail(function() {
                $body.html('<p class="dist-hint">Ошибка загрузки истории.</p>');
            });
        }

        /** Called before settings are saved — collect values */
        self.onSave = function() {
            var $container = $settingsContainer;
            if (!$container || !$container.length) {
                return true;
            }

            self.params.server_url          = $.trim($container.find('.js-server-url').val());
            self.params.distribution_method = $container.find('.js-dist-method').val();
            self.params.rules               = collectRules($container);

            // Save queue state to backend
            if (self.params.server_url) {
                apiRequest('/api/settings', {
                    account_id: getAccountId(),
                    settings:   self.params
                }, 'PUT');
            }

            return true;
        };

        /** Digital Pipeline — settings panel */
        self.dpSettings = function() {
            var settings = getSettings();

            return {
                render: function($container, dpSettings) {
                    $container.html([
                        '<div class="dist-dp-settings">',
                        '  <div class="dist-field">',
                        '    <label class="dist-label">Менеджеры для этого этапа</label>',
                        '    <div class="js-dp-managers-list dist-managers-list"></div>',
                        '    <button type="button" class="js-dp-add-manager dist-btn dist-btn--secondary dist-btn--sm">',
                        '      + Добавить менеджера',
                        '    </button>',
                        '  </div>',
                        '  <div class="dist-field">',
                        '    <label class="dist-label">',
                        '      <input type="checkbox" class="js-dp-check-history" ',
                                    ((dpSettings && dpSettings.check_history) ? 'checked' : '') + ' />',
                        '      Учитывать историю контакта/компании',
                        '    </label>',
                        '  </div>',
                        '  <div class="dist-field">',
                        '    <label class="dist-label">',
                        '      <input type="checkbox" class="js-dp-check-schedule" ',
                                    ((dpSettings && dpSettings.check_schedule) ? 'checked' : '') + ' />',
                        '      Учитывать рабочее расписание',
                        '    </label>',
                        '  </div>',
                        '</div>'
                    ].join(''));

                    // Render saved managers
                    _.each((dpSettings && dpSettings.managers) || [], function(manager) {
                        addManagerRow({ find: function(s) { return $container.find('.js-dp-managers-list'); } }, manager);
                    });

                    // Replace addManagerRow helper for dp context
                    $container.on('click', '.js-dp-add-manager', function() {
                        var users = getUsers();
                        var options = _.map(users, function(u) {
                            return '<option value="' + u.id + '">' + _.escape(u.name) + '</option>';
                        }).join('');
                        $container.find('.js-dp-managers-list').append(
                            '<div class="dist-manager-row">' +
                            '<select class="js-dp-manager-select dist-select dist-select--inline">' +
                            '<option value="">— выберите —</option>' + options +
                            '</select>' +
                            '<button type="button" class="js-dp-remove-manager dist-btn dist-btn--danger dist-btn--sm">&#x2715;</button>' +
                            '</div>'
                        );
                    });

                    $container.on('click', '.js-dp-remove-manager', function() {
                        $(this).closest('.dist-manager-row').remove();
                    });
                },

                collect: function($container) {
                    var managers = [];
                    $container.find('.js-dp-manager-select').each(function() {
                        var id = $(this).val();
                        if (id) managers.push({ id: id });
                    });
                    return {
                        managers:       managers,
                        check_history:  $container.find('.js-dp-check-history').is(':checked'),
                        check_schedule: $container.find('.js-dp-check-schedule').is(':checked')
                    };
                }
            };
        };

        /** Digital Pipeline — action triggered on event */
        self.dpInit = function(pipeline, status, lead) {
            var dpSettings = self.params.dp || {};
            handleDpEvent({
                lead:           lead,
                pipeline_id:    pipeline.id,
                lead_status_id: status.id
            }, dpSettings);
            return true;
        };

        /** Called when lead is created/updated (non-DP hook) */
        self.lead_selected = function() {
            return true;
        };

        /** Called to destroy / clean up */
        self.destroy = function() {
            return true;
        };

        /** Called when widget loaded on a page but not yet initialized */
        self.contacts = {
            selected: function() { return true; }
        };

        // ═════════════════════════════════════════════════════════════════════
        //   amoCRM (interface_version 2) вызывает колбэки ТОЛЬКО через
        //   this.callbacks. Функции определены выше на self и используют
        //   замыкание, поэтому просто ссылаемся на них (эталон — «Дубли»).
        // ═════════════════════════════════════════════════════════════════════
        self.callbacks = {
            render:           self.render,
            init:             self.init,
            bind_actions:     self.bind_actions,
            settings:         self.settings,
            advancedSettings: self.advancedSettings,
            initMenuPage:     self.initMenuPage,
            dpSettings:       self.dpSettings,
            onSave:           self.onSave,
            destroy:          self.destroy
        };

        return self;
    };

    return CustomWidget;
});
