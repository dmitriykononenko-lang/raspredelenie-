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
        var WIDGET_VERSION = '1.1.4';

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
            recalcRuleIndexes($container);
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

        // ─── Сохранение правил напрямую в бэкенд ──────────────────────────────
        // Кнопка «Сохранить» amoCRM в модалке виджета появляется только при
        // изменении ЕГО собственных полей и не реагирует на наш редактор правил,
        // поэтому правила сохраняем сами: своя кнопка → PUT /api/settings.
        // Источник истины — бэкенд (rules используются DistributionService).
        function buildSettingsPayload($scope) {
            var cur = getSettings();
            var $url = $scope.find('.js-server-url');
            var $met = $scope.find('.js-dist-method');
            return {
                server_url:          $url.length ? $.trim($url.val()) : (cur.server_url || ''),
                security_key:        cur.security_key || '',
                distribution_method: $met.length ? ($met.val() || 'round_robin') : (cur.distribution_method || 'round_robin'),
                rules:               collectRules($scope)
            };
        }

        function saveRulesToBackend($scope, $btn) {
            var payload = buildSettingsPayload($scope);
            var unsaved = $scope.find('.dist-rule-row').length - payload.rules.length;
            if ($btn && $btn.length) $btn.prop('disabled', true).data('label', $btn.text()).text('Сохраняем…');

            // Держим amo-параметры в синхроне (не обязательно для работы).
            try {
                self.params = $.extend(self.params || {}, {
                    server_url:          payload.server_url,
                    distribution_method: payload.distribution_method,
                    rules:               payload.rules
                });
                if (typeof self.set_settings === 'function') {
                    self.set_settings({
                        server_url:          payload.server_url,
                        distribution_method: payload.distribution_method,
                        rules:               payload.rules
                    });
                }
            } catch (e) {}

            return apiRequest('/api/settings', {
                account_id: getAccountId(),
                settings:   payload
            }, 'PUT').done(function() {
                var msg = 'Правила сохранены (' + payload.rules.length + ')';
                if (unsaved > 0) msg += '. Пропущено ' + unsaved + ' — без воронки или без менеджеров';
                if (rdRoot() && $.contains(rdRoot()[0], $scope[0])) toast(msg); else notify(msg, unsaved > 0 ? 'info' : 'success');
                var $st = $scope.find('.js-rules-status');
                if ($st.length) $st.text('Сохранено ' + new Date().toLocaleTimeString('ru-RU') + '. Правил: ' + payload.rules.length).css('color', '#1a7a4a');
            }).fail(function(xhr) {
                console.error('[DealDist] save settings failed', xhr);
                notify('Не удалось сохранить правила. Проверьте, что сервер доступен.', 'error');
                var $st = $scope.find('.js-rules-status');
                if ($st.length) $st.text('Ошибка сохранения').css('color', '#d22730');
            }).always(function() {
                if ($btn && $btn.length) $btn.prop('disabled', false).text($btn.data('label') || 'Сохранить правила');
            });
        }

        // Загрузить актуальные настройки с бэкенда (там источник истины),
        // с фолбэком на amo-параметры, если бэкенд недоступен.
        function loadSettingsFromBackend() {
            var d = $.Deferred();
            apiRequest('/api/settings?account_id=' + encodeURIComponent(getAccountId()), null, 'GET')
                .done(function(resp) {
                    var s = $.extend(true, {}, DEFAULT_SETTINGS, getSettings());
                    if (resp && typeof resp === 'object') {
                        if (resp.server_url)          s.server_url          = resp.server_url;
                        if (resp.distribution_method) s.distribution_method = resp.distribution_method;
                        if (_.isArray(resp.rules))    s.rules               = resp.rules;
                    }
                    d.resolve(s, true);
                })
                .fail(function() { d.resolve(getSettings(), false); });
            return d.promise();
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
                link.href = base.replace(/\/$/, '') + '/css/widget.css?v=' + WIDGET_VERSION;
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

            // Модалка карточки amoМаркета намеренно компактная: здесь только
            // быстрый ростер участия и расписания. Правила и История (Отчёт по
            // распределениям) живут на полной странице «Распределение сделок»
            // (Настройки → Распределение сделок) — там больше места.
            var settingsUrl = (function() {
                var code = (self.params && self.params.widget_code) || getSettings().widget_code || '';
                return code ? '/settings/widgets/' + code + '/' : '/settings/widgets/';
            })();

            $container.html([
                '<div class="dist-settings">',

                // ── Tab navigation ──────────────────────────────────────────
                '  <div class="dist-tabs">',
                '    <button type="button" class="dist-tab dist-tab--active" data-tab="status">Сотрудники</button>',
                '    <button type="button" class="dist-tab" data-tab="schedules">Расписания</button>',
                '  </div>',

                // ── Tab: Сотрудники (кто участвует в распределении) ─────────
                '  <div class="dist-tab-panel" data-panel="status">',
                '    <div class="dist-section">',
                '      <div class="dist-log-toolbar">',
                '        <h4 class="dist-section__title" style="margin:0;">Сотрудники в распределении</h4>',
                '        <button type="button" class="js-refresh-status dist-btn dist-btn--secondary dist-btn--sm">&#x21bb; Обновить</button>',
                '      </div>',
                '      <p class="dist-hint">Тумблер справа задаёт, участвует ли сотрудник в распределении. Выключенным сделки не назначаются. Показаны только активные пользователи amoCRM.</p>',
                '      <div class="js-status-body dist-status-body"><p class="dist-hint">Загрузка...</p></div>',
                '    </div>',
                '    <div class="dist-section">',
                '      <p class="dist-hint">Правила распределения и история — на полной странице: ',
                '        <a href="' + settingsUrl + '" target="_blank" style="color:#2b7de9">Настройки → Распределение сделок</a>.</p>',
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
            });

            // Тумблер «в распределении / вне» прямо в настройках.
            $container.on('change', '.js-mgr-online', function() {
                var $i = $(this);
                setStatus($i.data('uid'), $i.prop('checked'), $i);
            });
            $container.on('click', '.js-refresh-status', function() {
                renderStatusTab($container);
            });

            // Вкладка «Сотрудники» открыта по умолчанию — грузим сразу.
            renderStatusTab($container);

            return true;
        };

        // Ростер сотрудников с тумблерами участия — версия для панели settings.
        function renderStatusTab($container) {
            var $body = $container.find('.js-status-body');
            if (!$body.length) return;
            $body.html('<p class="dist-hint">' + _.escape(self.i18n('common.loading')) + '</p>');
            ensureUsers(function(users) {
                if (!users || !users.length) {
                    $body.html(emptyState(self.i18n('status.no_users')));
                    return;
                }
                apiRequest('/api/status', null, 'GET')
                    .done(function(resp) {
                        renderStatusTable($body, users, (resp && resp.statuses) || {});
                    })
                    .fail(function() {
                        renderStatusTable($body, users, {});
                        notify(self.i18n('status.load_error'), 'error');
                    });
            });
        }

        // ═════════════════════════════════════════════════════════════════════
        //   Advanced settings — полностраничный виджет (сайдбар + модули)
        // ═════════════════════════════════════════════════════════════════════

        // Кэш активного ростера. Источник истины — amo API /api/v4/users
        // (там есть rights.is_active и rights.group_id). Фолбэк — constant('managers').
        var _users = null, _usersLoading = false, _usersWaiters = [];

        // Названия отделов amoCRM: id → имя. ВАЖНО: AMOCRM.constant('account')
        // .users_groups в этом кабинете пуст, поэтому берём отделы из
        // /api/v4/account?with=users_groups (там id 0 = «Отдел продаж» и т.д.).
        // Синхронный фолбэк на constant остаётся на случай недоступности API.
        var _groupMap = null;
        function amoGroupNames() {
            if (_groupMap) return _groupMap;
            var map = {};
            try {
                var acc = AMOCRM.constant('account');
                var groups = (acc && (acc.users_groups || acc.groups)) || [];
                _.each(groups, function(g) { if (g && g.id != null) map[String(g.id)] = g.name || ''; });
            } catch (e) {}
            return map;
        }
        // Асинхронно тянет реальные отделы из API и кэширует в _groupMap.
        function loadGroupMap() {
            var d = $.Deferred();
            $.ajax({ url: '/api/v4/account?with=users_groups', type: 'GET', dataType: 'json' })
                .done(function(resp) {
                    var groups = (resp && resp._embedded && resp._embedded.users_groups) || resp.users_groups || [];
                    var map = {};
                    _.each(groups, function(g) { if (g && g.id != null) map[String(g.id)] = g.name || ''; });
                    _groupMap = (Object.keys(map).length ? map : amoGroupNames());
                    d.resolve(_groupMap);
                })
                .fail(function() { _groupMap = amoGroupNames(); d.resolve(_groupMap); });
            return d.promise();
        }

        // Фолбэк-ростер из constant('managers') — только активные, если можно определить.
        function getUsersFromConstant() {
            var users = [], gmap = amoGroupNames();
            try {
                var mgr = (window.AMOCRM && AMOCRM.constant && AMOCRM.constant('managers')) || null;
                if (mgr && typeof mgr === 'object') {
                    _.each(mgr, function(u) {
                        if (!u || u.id == null) return;
                        // Отсеиваем деактивированных, если признак присутствует.
                        var active = u.active;
                        if (active === false || active === 'N' || active === 0 || active === '0') return;
                        var gid = (u.group_id != null) ? String(u.group_id) : null;
                        var gname = (gid && gmap[gid]) ? gmap[gid] : '';
                        // Не показываем служебные "GROUP_0"/"GROUP_xxx" как имя отдела.
                        var rawG = u.group_name || u.group || '';
                        if (!gname && rawG && !/^GROUP[_-]?\d+$/i.test(String(rawG))) gname = rawG;
                        users.push({
                            id:    parseInt(u.id, 10),
                            name:  u.option || u.name || ('#' + u.id),
                            group: gname
                        });
                    });
                }
            } catch (e) {}
            return users;
        }

        // Синхронный доступ (для мест, где нельзя ждать): кэш или фолбэк.
        function getUsers() {
            return (_users && _users.length) ? _users : getUsersFromConstant();
        }

        // Асинхронно гарантирует актуальный активный ростер и вызывает cb(users).
        function ensureUsers(cb) {
            if (_users) { if (cb) cb(_users); return; }
            if (cb) _usersWaiters.push(cb);
            if (_usersLoading) return;
            _usersLoading = true;
            // Сначала отделы (реальные названия), потом активные пользователи.
            loadGroupMap().always(function(gmap) {
                gmap = gmap || amoGroupNames();
                $.ajax({ url: '/api/v4/users?limit=250', type: 'GET', dataType: 'json' })
                    .done(function(resp) {
                        var list = (resp && resp._embedded && resp._embedded.users) || [];
                        var arr = [];
                        _.each(list, function(u) {
                            // amo отдаёт и активных, и деактивированных — оставляем активных.
                            if (u.rights && u.rights.is_active === false) return;
                            // group_id может быть 0 (валидный отдел «Отдел продаж»).
                            var gid = (u.rights && u.rights.group_id != null) ? String(u.rights.group_id) : null;
                            arr.push({
                                id:    parseInt(u.id, 10),
                                name:  u.name || ('#' + u.id),
                                group: (gid != null && gmap[gid]) ? gmap[gid] : ''
                            });
                        });
                        _users = arr.length ? arr : getUsersFromConstant();
                    })
                    .fail(function() { _users = getUsersFromConstant(); })
                    .always(function() {
                        _usersLoading = false;
                        var ws = _usersWaiters.slice(); _usersWaiters = [];
                        _.each(ws, function(f) { try { f(_users); } catch (e) {} });
                    });
            });
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

        // Ищем видимую рабочую область виджета. КЛЮЧЕВОЕ: НЕ монтируемся после
        // заголовка внутри .content__top__preset — amo держит этот блок со
        // сдвигом вверх (transform/отрицательный top), из-за чего верх страницы
        // уезжает за экран, а снизу остаётся пустое место. Контейнер
        // #work-area-<code> находится в нормальном потоке и виден целиком.
        function findWorkArea() {
            var code = (self.params && self.params.widget_code) || (getSettings().widget_code) || '';
            var $a = code ? $('#work-area-' + code) : $();
            if (!$a.length) $a = $('div[id^="work-area-"]:visible').first();
            if (!$a.length) $a = $('div[id^="work-area-"]').first();
            // Явно НЕ берём .content__top__preset (сдвинут вверх).
            if (!$a.length) $a = $('.widget_settings_block__content_inner, .widget_settings_block, .list-settings__work-area').first();
            return $a;
        }

        function mountAdvancedPage(useWide) {
            var attempts = 0;
            function mount() {
                var $area = findWorkArea();
                if (!$area.length) {
                    if (attempts++ < 25) { setTimeout(mount, 120); return; }
                    // Последний фолбэк — основная область контента, но не preset.
                    $area = $('#page_holder .content:not(.content__top__preset), #content').first();
                    if (!$area.length) $area = $(document.body);
                }
                if ($area.find('.dist-adv').length) return; // уже отрисовано
                $area.empty();
                var $mount = $('<div class="dist-adv' + (useWide ? ' dist-adv--wide' : '') + '"></div>');
                $area.append($mount);
                renderAdvancedPage($mount);
            }
            mount();
        }

        // Страница «Расширенные настройки» виджета (Настройки → Распределение сделок).
        self.advancedSettings = function() {
            injectCss();
            mountAdvancedPage(true);
            return true;
        };

        // Страница из левого меню (widget_page). Контейнер #work-area-<code>
        // amoCRM создаёт асинхронно — ищем с повтором, иначе страница пустая.
        self.initMenuPage = function(params) {
            injectCss();
            mountAdvancedPage(true);
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

        // Дизайн KO:AGENCY (редизайн). Пункт навигации сайдбара.
        function navRd(key, label, on) {
            return '<button class="rd-nav__i' + (on ? ' rd-nav__i--on' : '') +
                   '" data-p="' + key + '">' + _.escape(label) + '</button>';
        }

        function renderAdvancedPage($mount) {
            $advMount = $mount;
            $mount.html([
                '<div class="rd-scope"><div class="rd-page">',
                '  <div class="rd-top">',
                '    <div class="rd-brand"><div class="rd-brand__mk">KO<i>·</i>AGENCY</div>',
                '      <div class="rd-brand__sub">' + _.escape(self.i18n('advanced.title')) + '</div></div>',
                '    <div class="rd-toptabs js-toptabs" style="display:none">',
                '      <button class="rd-toptab rd-toptab--on" data-tab="rules">Правила</button>',
                '      <button class="rd-toptab" data-tab="schedules">Расписания</button>',
                '      <button class="rd-toptab" data-tab="templates">' + _.escape(self.i18n('nav.templates')) + '</button>',
                '    </div>',
                '  </div>',
                '  <div class="rd-body">',
                '    <aside class="rd-side">',
                '      <div class="rd-online"><div class="rd-online__row">',
                '        <span class="rd-online__dot"></span>',
                '        <span class="rd-online__lbl">' + _.escape(self.i18n('status.online')) + '</span>',
                '        <div class="rd-tgl rd-tgl--green js-self-online" style="margin-left:auto"><div class="rd-tgl__knob"></div></div>',
                '      </div><div class="rd-online__sub">' + _.escape(self.i18n('status.self_hint')) + '</div></div>',
                '      <nav class="rd-nav">',
                         navRd('status',    self.i18n('status.title'),        true),
                         navRd('dist',      self.i18n('nav.report_dist'),     false),
                         navRd('statusrep', self.i18n('nav.report_status'),   false),
                         navRd('deputy',    self.i18n('nav.report_deputy'),   false),
                         navRd('settings',  self.i18n('nav.settings'),        false),
                '      </nav>',
                '    </aside>',
                '    <main class="rd-content"><div class="js-panel"></div></main>',
                '  </div>',
                '</div></div>'
            ].join(''));

            bindAdvancedEvents($mount);
            ensureUsers();          // прогреваем активный ростер заранее
            switchPanel($mount, 'status');
        }

        function switchPanel($mount, key) {
            $mount.find('.rd-nav__i').removeClass('rd-nav__i--on');
            $mount.find('.rd-nav__i[data-p="' + key + '"]').addClass('rd-nav__i--on');
            var $tabs  = $mount.find('.js-toptabs');
            var $panel = $mount.find('.js-panel');
            if (key === 'settings') {
                $tabs.css('display', '');
                renderSettingsSurface($mount, $panel, $mount.data('settingsTab') || 'rules');
            } else {
                $tabs.css('display', 'none');
                if (key === 'dist')            renderReportPanel($mount, $panel, 'dist');
                else if (key === 'statusrep')  renderReportPanel($mount, $panel, 'statusrep');
                else if (key === 'deputy')     renderReportPanel($mount, $panel, 'deputy');
                else                           renderStatusPanel($mount, $panel);
            }
        }

        // Тёмный тост снизу по центру (эталон дизайна), автоскрытие ~2.4с.
        // Корень с CSS-переменными (.rd-scope). Модалки/тосты монтируем СЮДА,
        // иначе var(--...) не наследуются и стили ломаются.
        function rdRoot() {
            if (!$advMount || !$advMount.length) return null;
            var $s = $advMount.find('.rd-scope').first();
            return $s.length ? $s : $advMount;
        }

        var _toastTimer = null;
        function toast(text) {
            var $root = rdRoot();
            if (!$root) { notify(text, 'success'); return; }
            $root.find('.rd-toast').remove();
            var $t = $('<div class="rd-toast"><i>✓</i>' + _.escape(text) + '</div>');
            $root.append($t);
            if (_toastTimer) clearTimeout(_toastTimer);
            _toastTimer = setTimeout(function() { $t.fadeOut(150, function() { $t.remove(); }); }, 2400);
        }

        // ── Панель: Статусы (редизайн) ──────────────────────────────────────────
        function renderStatusPanel($mount, $panel) {
            $panel.html([
                '<div class="rd-h1">' + _.escape(self.i18n('status.title_page')) + '</div>',
                '<div class="rd-desc">' + _.escape(self.i18n('status.desc')) + '</div>',
                '<div class="rd-filters"><div style="font-size:13.5px">' + _.escape(self.i18n('status.participate')) +
                  ' <span class="rd-mono js-active-count" style="font-weight:700"></span></div>',
                '  <button class="rd-btn rd-btn--ghost rd-btn--sm js-status-refresh" style="margin-left:auto">↻ ' +
                     _.escape(self.i18n('common.refresh')) + '</button></div>',
                '<div class="rd-filters"><div class="rd-filter rd-filter--ph">' + _.escape(self.i18n('status.col_manager')) + '</div>',
                '  <div class="rd-dd js-dept-dd"><div class="rd-filter js-dept-btn" style="min-width:190px">' +
                     '<span class="js-dept-label">' + _.escape(self.i18n('status.all_depts')) + '</span><span class="rd-caret">▾</span></div></div></div>',
                '<div class="rd-card"><div class="rd-scroll js-status-body"><div class="rd-row" style="color:#8b95a7">' +
                   _.escape(self.i18n('common.loading')) + '</div></div></div>'
            ].join(''));

            var $body = $panel.find('.js-status-body');
            var deptFilter = $mount.data('deptFilter') || '';
            ensureUsers(function(users) {
                if (!users || !users.length) { $body.html(emptyState(self.i18n('status.no_users'))); return; }
                apiRequest('/api/status', null, 'GET')
                    .done(function(resp) {
                        var st = (resp && resp.statuses) || {};
                        $mount.data('statuses', st);
                        renderStatusTableRd($body, users, st, deptFilter);
                        syncSelfToggle($mount, st);
                        updateActiveCount($mount);
                    })
                    .fail(function() {
                        $mount.data('statuses', {});
                        renderStatusTableRd($body, users, {}, deptFilter);
                        updateActiveCount($mount);
                        notify(self.i18n('status.load_error'), 'error');
                    });
            });
        }

        // Таблица сотрудников, сгруппированная по отделам (редизайн).
        function renderStatusTableRd($body, users, statuses, deptFilter) {
            var groups = {};
            _.each(users, function(u) {
                var g = u.group || '—';
                if (deptFilter && g !== deptFilter) return;
                (groups[g] = groups[g] || []).push(u);
            });
            var html = ['<div class="rd-thead rd-sgrid"><div class="rd-name"><span class="rd-check js-check-all"></span>' +
                        _.escape(self.i18n('status.col_manager')) + '</div><div>' + _.escape(self.i18n('status.col_status')) + '</div></div>'];
            var any = false;
            _.each(groups, function(list, group) {
                any = true;
                if (group !== '—') html.push('<div class="rd-grp">' + _.escape(group) + '</div>');
                _.each(list, function(u) {
                    var online = !Object.prototype.hasOwnProperty.call(statuses, String(u.id)) ||
                                 !!(statuses[String(u.id)] && statuses[String(u.id)].online);
                    html.push(
                        '<div class="rd-row rd-sgrid">' +
                        '<div class="rd-name"><span class="rd-check js-row-check" data-uid="' + u.id + '"></span>' +
                          '<span class="rd-av" style="background:' + avatarColor(u.id) + '">' + _.escape(initials(u.name)) + '</span>' + _.escape(u.name) + '</div>' +
                        '<div class="rd-stat"><div class="rd-tgl js-mgr-online' + (online ? ' rd-tgl--on' : '') + '" data-uid="' + u.id + '"><div class="rd-tgl__knob"></div></div>' +
                          '<span class="rd-statlbl js-stat-lbl" style="color:' + (online ? '#1a7a4a' : '#98a1b3') + '">' +
                             _.escape(online ? self.i18n('status.on') : self.i18n('status.off')) + '</span></div>' +
                        '</div>'
                    );
                });
            });
            if (!any) html.push('<div class="rd-row" style="color:#8b95a7">' + _.escape(self.i18n('status.no_users')) + '</div>');
            $body.html(html.join(''));
        }

        // Дропдаун фильтра по отделу (в панели «Статусы»).
        function toggleDeptMenu($mount) {
            var $dd = $mount.find('.js-dept-dd');
            if ($dd.find('.rd-dd__menu').length) { $dd.find('.rd-dd__menu').remove(); return; }
            var users = getUsers();
            var depts = _.uniq(_.compact(_.map(users, function(u) { return u.group; })));
            var cur   = $mount.data('deptFilter') || '';
            var items = ['<div class="rd-dd__i' + (!cur ? ' rd-dd__i--on' : '') + '" data-dept="">' + _.escape(self.i18n('status.all_depts')) + '</div>'];
            _.each(depts, function(d) {
                items.push('<div class="rd-dd__i' + (cur === d ? ' rd-dd__i--on' : '') + '" data-dept="' + _.escape(d) + '">' + _.escape(d) + '</div>');
            });
            $dd.append('<div class="rd-dd__menu">' + items.join('') + '</div>');
        }

        // Счётчик «участвуют: N из M» — по классам тумблеров (не зависит от формата API).
        function updateActiveCount($scope) {
            $scope = $scope || $advMount;
            if (!$scope || !$scope.length) return;
            var total = $scope.find('.js-mgr-online').length;
            var on    = $scope.find('.js-mgr-online.rd-tgl--on').length;
            $scope.find('.js-active-count').text(on + ' ' + self.i18n('status.of') + ' ' + total);
        }

        // ── Панели отчётов (редизайн) ───────────────────────────────────────────
        function renderReportPanel($mount, $panel, kind) {
            var titles = {
                dist:      [self.i18n('nav.report_dist'),   self.i18n('report.dist_desc')],
                statusrep: [self.i18n('nav.report_status'), self.i18n('report.status_desc')],
                deputy:    [self.i18n('nav.report_deputy'), self.i18n('report.deputy_desc')]
            };
            var t = titles[kind] || titles.dist;
            $panel.html(
                '<div class="rd-h1">' + _.escape(t[0]) + '</div>' +
                '<div class="rd-desc">' + _.escape(t[1]) + '</div>' +
                '<div class="rd-card"><div class="rd-scroll js-report-body"><div class="rd-row" style="color:#8b95a7">' +
                _.escape(self.i18n('common.loading')) + '</div></div></div>'
            );
            var $body = $panel.find('.js-report-body');

            if (kind === 'deputy') {
                // Заместители — бэкенда нет: честное пустое состояние.
                $body.html('<div class="rd-empty">' + _.escape(self.i18n('report.deputy_empty')) + '</div>');
                return;
            }
            var users = getUsers();
            var nameOf = function(id) { var u = _.find(users, function(x){ return String(x.id) === String(id); }); return u ? u.name : ('#' + id); };

            if (kind === 'dist') {
                apiRequest('/api/log', null, 'GET').done(function(resp) {
                    var rows = (resp && (resp.log || resp.items || resp)) || [];
                    if (!rows.length) { $body.html('<div class="rd-empty">' + _.escape(self.i18n('report.empty')) + '</div>'); return; }
                    var h = ['<div class="rd-thead" style="display:grid;grid-template-columns:1.2fr 1fr 1.2fr 1.4fr .8fr;gap:12px"><div>' +
                             _.escape(self.i18n('report.col_date')) + '</div><div>' + _.escape(self.i18n('report.col_result')) + '</div><div>' +
                             _.escape(self.i18n('status.col_manager')) + '</div><div>' + _.escape(self.i18n('report.col_stage')) + '</div><div>' +
                             _.escape(self.i18n('report.col_deal')) + '</div></div>'];
                    _.each(rows, function(l) {
                        h.push('<div class="rd-row" style="display:grid;grid-template-columns:1.2fr 1fr 1.2fr 1.4fr .8fr;gap:12px;align-items:center;font-size:13px">' +
                          '<div class="rd-mono" style="color:#586173;font-size:12px">' + _.escape(fmtTs(l.ts || l.time || l.date)) + '</div>' +
                          '<div><span class="rd-badge rd-badge--algo">' + _.escape(l.result || l.status || '—') + '</span></div>' +
                          '<div style="font-weight:700">' + _.escape(l.user_id ? nameOf(l.user_id) : '—') + '</div>' +
                          '<div style="color:#586173">' + _.escape(l.stage_id || l.stage || '—') + '</div>' +
                          '<div class="rd-mono" style="font-size:12.5px">' + _.escape(l.lead_id || l.deal || '—') + '</div></div>');
                    });
                    $body.html(h.join(''));
                }).fail(function() { $body.html('<div class="rd-empty">' + _.escape(self.i18n('report.load_error')) + '</div>'); });
            } else { // statusrep
                apiRequest('/api/status/history?limit=200', null, 'GET').done(function(resp) {
                    var rows = (resp && resp.history) || [];
                    if (!rows.length) { $body.html('<div class="rd-empty">' + _.escape(self.i18n('report.empty')) + '</div>'); return; }
                    var h = ['<div class="rd-thead" style="display:grid;grid-template-columns:1.2fr 1.4fr 1fr 1fr;gap:12px"><div>' +
                             _.escape(self.i18n('report.col_date')) + '</div><div>' + _.escape(self.i18n('status.col_manager')) + '</div><div>' +
                             _.escape(self.i18n('report.col_was')) + '</div><div>' + _.escape(self.i18n('report.col_became')) + '</div></div>'];
                    _.each(rows, function(l) {
                        var on = !!l.online;
                        h.push('<div class="rd-row" style="display:grid;grid-template-columns:1.2fr 1.4fr 1fr 1fr;gap:12px;align-items:center;font-size:13px">' +
                          '<div class="rd-mono" style="color:#586173;font-size:12px">' + _.escape(fmtTs(l.ts)) + '</div>' +
                          '<div style="font-weight:700">' + _.escape(nameOf(l.user_id)) + '</div>' +
                          '<div style="color:#586173">' + _.escape(on ? self.i18n('status.off') : self.i18n('status.on')) + '</div>' +
                          '<div style="color:' + (on ? '#1a7a4a' : '#98a1b3') + '">' + _.escape(on ? self.i18n('status.on') : self.i18n('status.off')) + '</div></div>');
                    });
                    $body.html(h.join(''));
                }).fail(function() { $body.html('<div class="rd-empty">' + _.escape(self.i18n('report.load_error')) + '</div>'); });
            }
        }

        function fmtTs(ts) {
            if (!ts) return '—';
            var n = parseInt(ts, 10);
            if (!isNaN(n) && String(ts).length <= 11) { try { return new Date(n * 1000).toLocaleString('ru-RU'); } catch (e) {} }
            return String(ts);
        }

        // ── Поверхность «Настройки» (вкладки Шаблоны / Рабочее время) ────────────
        function renderSettingsSurface($mount, $panel, tab) {
            $mount.data('settingsTab', tab);
            $mount.find('.rd-toptab').removeClass('rd-toptab--on');
            $mount.find('.rd-toptab[data-tab="' + tab + '"]').addClass('rd-toptab--on');
            if (tab === 'rules') {
                renderRulesPanel($mount, $panel);
            } else if (tab === 'schedules') {
                renderSchedulesPanel($mount, $panel);
            } else if (tab === 'hours') {
                $panel.html(
                    '<div class="rd-h1">' + _.escape(self.i18n('nav.worktime')) + '</div>' +
                    '<div class="rd-desc">' + _.escape(self.i18n('hours.desc')) + '</div>' +
                    '<div class="rd-soon"><b>' + _.escape(self.i18n('common.soon')) + '</b>' + _.escape(self.i18n('hours.soon')) + '</div>'
                );
            } else {
                renderTemplatesPanel($mount, $panel);
            }
        }

        // ── Панель: Правила распределения (полная страница) ─────────────────────
        // Тот же редактор правил, что и в модалке amoМаркета, но на большой
        // странице и с сохранением напрямую в бэкенд.
        function renderRulesPanel($mount, $panel) {
            $panel.html(
                '<div class="rd-h1">Правила распределения</div>' +
                '<div class="rd-desc">Правило задаёт воронку, этап и менеджеров, между которыми делятся сделки. ' +
                'Срабатывает, когда сделка попадает на этап, где в Digital Pipeline стоит действие «Распределение».</div>' +
                '<div class="rd-card dist-settings dist-settings--adv js-rules-scope">' +
                '  <div class="dist-section">' +
                '    <div class="dist-row-2col">' +
                '      <div class="dist-field">' +
                '        <label class="dist-label">Метод распределения</label>' +
                '        <select class="js-dist-method dist-select">' +
                '          <option value="round_robin">Round Robin (по очереди)</option>' +
                '          <option value="workload">По загруженности</option>' +
                '        </select>' +
                '      </div>' +
                '      <div class="dist-field">' +
                '        <label class="dist-label">URL сервера</label>' +
                '        <input type="text" class="js-server-url dist-input" placeholder="' + DEFAULT_SERVER_URL + '" />' +
                '        <small class="dist-hint">Пусто = адрес по умолчанию.</small>' +
                '      </div>' +
                '    </div>' +
                '  </div>' +
                '  <div class="dist-section">' +
                '    <div class="js-rules-list dist-rules-list"><p class="dist-hint">Загрузка правил…</p></div>' +
                '    <div class="dist-rules-actions">' +
                '      <button type="button" class="js-add-rule dist-btn dist-btn--secondary">+ Добавить правило</button>' +
                '      <button type="button" class="js-save-rules dist-btn dist-btn--primary">Сохранить правила</button>' +
                '      <span class="js-rules-status dist-hint"></span>' +
                '    </div>' +
                '  </div>' +
                '</div>'
            );

            var $scope = $panel.find('.js-rules-scope');
            loadSettingsFromBackend().done(function(s, fromBackend) {
                $scope.find('.js-rules-list').empty();
                $scope.find('.js-dist-method').val(s.distribution_method || 'round_robin');
                if (s.server_url) $scope.find('.js-server-url').val(s.server_url);
                bindSettingsEvents($scope, s);
                recalcRuleIndexes($scope);
                if (!s.rules || !s.rules.length) {
                    $scope.find('.js-rules-status').text('Правил пока нет — добавьте первое').css('color', '#8b95a7');
                }
                if (!fromBackend) {
                    $scope.find('.js-rules-status').text('Сервер недоступен — показаны локальные настройки').css('color', '#d22730');
                }
            });

            $scope.on('click', '.js-save-rules', function() {
                saveRulesToBackend($scope, $(this));
            });
        }

        // ── Панель: Расписания (полная страница) — переиспользуем редактор ──────
        function renderSchedulesPanel($mount, $panel) {
            $panel.html(
                '<div class="rd-h1">Рабочие расписания</div>' +
                '<div class="rd-desc">Рабочие часы менеджеров. Учитываются, если в правиле включено «Учитывать рабочее расписание»: вне графика менеджер сделки не получает.</div>' +
                '<div class="rd-card dist-settings dist-settings--adv js-sched-scope">' +
                '  <div class="dist-tab-panel" data-panel="schedules">' +
                '    <div class="dist-section">' +
                '      <div class="js-schedules-list dist-schedules-list"></div>' +
                '      <div class="dist-schedule-add-row">' +
                '        <select class="js-schedule-user-select dist-select dist-select--inline">' +
                '          <option value="">— выберите менеджера —</option>' +
                '        </select>' +
                '        <button type="button" class="js-add-schedule dist-btn dist-btn--secondary">+ Добавить расписание</button>' +
                '      </div>' +
                '    </div>' +
                '  </div>' +
                '</div>'
            );
            renderSchedulesTab($panel.find('.js-sched-scope'));
        }

        // ── Панель: Шаблоны ─────────────────────────────────────────────────────
        function tplTypeLabel(type) {
            return self.i18n('templates.type_' + type) || type;
        }

        function renderTemplatesPanel($mount, $panel) {
            $panel.html(
                '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
                  '<div><div class="rd-h1">' + _.escape(self.i18n('templates.title')) + '</div>' +
                  '<div class="rd-desc">' + _.escape(self.i18n('templates.desc')) + ' Шаблоны — заготовки; само распределение выполняется по правилам на вкладке «Правила».</div></div>' +
                  '<button class="rd-btn rd-btn--primary js-tpl-add">+ ' + _.escape(self.i18n('templates.add')) + '</button></div>' +
                '<div class="js-tpl-body" style="display:flex;flex-direction:column;gap:10px;margin-top:18px">' +
                  '<div style="color:#8b95a7;padding:14px">' + _.escape(self.i18n('common.loading')) + '</div></div>'
            );
            loadTemplates($panel);
        }

        function loadTemplates($panel) {
            var $body = $panel.find('.js-tpl-body');
            apiRequest('/api/templates', null, 'GET')
                .done(function(resp) { renderTemplatesTable($body, (resp && resp.templates) || []); })
                .fail(function() { $body.html(errorState(self.i18n('templates.load_error'))); });
        }

        // Условие-подпись карточки шаблона (из filters, если заданы).
        function tplCondLabel(t) {
            var f = t.filters || {}, p = [];
            if (t.pipeline_id)            p.push(self.i18n('templates.cond_pipeline'));
            if (f.name_contains)          p.push(self.i18n('templates.cond_name') + ': ' + f.name_contains);
            if (f.budget_min)             p.push(self.i18n('templates.cond_budget') + ' ' + f.budget_min);
            if ((f.tags || []).length)    p.push(self.i18n('templates.cond_tags') + ': ' + f.tags.join(', '));
            return p.length ? p.join(' · ') : self.i18n('templates.cond_any');
        }

        // Карточки-правила (редизайн): приоритет, название, бейдж алгоритма,
        // стек аватаров участников, «Изменить», тумблер вкл/выкл.
        function renderTemplatesTable($body, templates) {
            tplCache = {};
            if (!templates.length) { $body.html(emptyState(self.i18n('templates.empty'))); return; }
            var users = getUsers();
            var html = [];
            _.each(templates, function(t, idx) {
                tplCache[t.id] = t;
                var mgrs    = t.managers || [];
                var avatars = _.map(mgrs.slice(0, 3), function(m) {
                    var u  = _.find(users, function(x) { return String(x.id) === String(m.id); });
                    var nm = u ? u.name : ('#' + m.id);
                    return '<span class="rd-av" style="background:' + avatarColor(m.id) + '">' + _.escape(initials(nm)) + '</span>';
                }).join('');
                var enabled = t.enabled !== false;
                html.push(
                    '<div class="rd-rule">' +
                    '<div class="rd-rule__pri">' + (idx + 1) + '</div>' +
                    '<div style="flex:1;min-width:0"><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">' +
                      '<div style="font-size:14.5px;font-weight:700">' + _.escape(t.name) + '</div>' +
                      '<span class="rd-badge rd-badge--algo">' + _.escape(tplTypeLabel(t.type)) + '</span></div>' +
                      '<div style="font-size:12.5px;color:#586173;margin-top:4px">' + _.escape(tplCondLabel(t)) + '</div></div>' +
                    '<div class="rd-avstack">' + avatars + '</div>' +
                    '<div style="font-size:12.5px;color:#586173;white-space:nowrap">' + mgrs.length + ' ' + _.escape(self.i18n('templates.members')) + '</div>' +
                    '<button class="rd-btn rd-btn--ghost rd-btn--sm js-tpl-edit" data-id="' + _.escape(t.id) + '">' + _.escape(self.i18n('common.edit')) + '</button>' +
                    '<div class="rd-tgl js-tpl-toggle' + (enabled ? ' rd-tgl--on' : '') + '" data-id="' + _.escape(t.id) + '"><div class="rd-tgl__knob"></div></div>' +
                    '</div>'
                );
            });
            $body.html(html.join(''));
        }

        // ── Модалка редактора шаблона (редизайн) ─────────────────────────────────
        var ALGO_META = {
            round_robin: { g: '🔁', d: 'algo_rr' },
            workload:    { g: '⚖️', d: 'algo_wl' },
            percent:     { g: '％', d: 'algo_pct' }
        };
        function openTemplateModal(tpl) {
            var isEdit = !!tpl;
            tpl = tpl || { name: '', type: 'round_robin', managers: [], check_history: false, check_schedule: false, filters: {} };
            var curType = tpl.type || 'round_robin';
            var users   = getUsers();
            var memberIds = _.map(tpl.managers || [], function(m) { return String(m.id); });
            var pctOf     = function(id) { var m = _.find(tpl.managers || [], function(x) { return String(x.id) === String(id); }); return (m && m.percent != null) ? m.percent : 0; };

            var algos = _.map(['round_robin', 'workload', 'percent'], function(tp) {
                var m = ALGO_META[tp];
                return '<div class="rd-algo' + (curType === tp ? ' rd-algo--on' : '') + ' js-algo" data-type="' + tp + '">' +
                       '<div class="rd-algo__hd"><div class="rd-algo__ic">' + m.g + '</div>' +
                       '<div class="rd-algo__t">' + _.escape(tplTypeLabel(tp)) + '</div>' +
                       '<span class="js-algo-check" style="margin-left:auto;color:#d22730;font-size:15px;' + (curType === tp ? '' : 'display:none') + '">✓</span></div>' +
                       '<div class="rd-algo__d">' + _.escape(self.i18n('templates.' + m.d)) + '</div></div>';
            }).join('');

            var members = _.map(users, function(u) {
                var on = memberIds.indexOf(String(u.id)) !== -1;
                return '<div class="rd-mchip js-member' + (on ? ' rd-mchip--on' : '') + '" data-uid="' + u.id + '">' +
                       '<span class="rd-av rd-av--sm" style="background:' + avatarColor(u.id) + '">' + _.escape(initials(u.name)) + '</span>' +
                       _.escape(u.name) +
                       '<input type="number" min="0" max="100" class="js-member-pct" value="' + pctOf(u.id) + '" style="' + (curType === 'percent' ? '' : 'display:none') + '">' +
                       '<span class="js-member-check" style="color:#d22730;font-weight:700;' + (on ? '' : 'display:none') + '">✓</span></div>';
            }).join('');

            var conds = _.map(tplCondChips(tpl), function(c) {
                return '<div class="rd-chip"><span class="rd-chip__k">' + _.escape(c.k) + '</span><span style="font-weight:700">' + _.escape(c.v) + '</span></div>';
            }).join('');

            var $modal = $(
                '<div class="rd-overlay js-modal-overlay"><div class="rd-modal">' +
                '  <div class="rd-modal__head"><div class="rd-modal__title">' +
                     _.escape(self.i18n(isEdit ? 'templates.modal_edit' : 'templates.modal_new')) +
                '    </div><button class="rd-x js-modal-close">×</button></div>' +
                '  <div class="rd-modal__body">' +
                '    <div><div class="rd-lbl">' + _.escape(self.i18n('templates.f_name')) + '</div>' +
                '      <input type="text" class="rd-field-input js-tpl-name" value="' + _.escape(tpl.name) + '"></div>' +
                '    <div><div class="rd-lbl">' + _.escape(self.i18n('templates.f_conditions')) + '</div>' +
                '      <div class="rd-chips">' + conds + '<div class="rd-chip rd-chip--add" title="' + _.escape(self.i18n('common.soon')) + '">+ ' + _.escape(self.i18n('templates.cond_add')) + '</div></div></div>' +
                '    <div><div class="rd-lbl">' + _.escape(self.i18n('templates.f_type')) + '</div>' +
                '      <div class="rd-algos js-algos">' + algos + '</div></div>' +
                '    <div><div class="rd-lbl">' + _.escape(self.i18n('templates.f_members')) + '</div>' +
                '      <div class="rd-chips js-members">' + members + '</div></div>' +
                '    <div><div class="rd-lbl">' + _.escape(self.i18n('templates.f_extra')) + '</div>' +
                '      <label style="display:flex;align-items:center;gap:9px;font-size:13px;cursor:pointer;margin-bottom:8px"><input type="checkbox" class="js-tpl-history"' + (tpl.check_history ? ' checked' : '') + '>' + _.escape(self.i18n('templates.f_history')) + '</label>' +
                '      <label style="display:flex;align-items:center;gap:9px;font-size:13px;cursor:pointer"><input type="checkbox" class="js-tpl-schedule"' + (tpl.check_schedule ? ' checked' : '') + '>' + _.escape(self.i18n('templates.f_schedule')) + '</label></div>' +
                '  </div>' +
                '  <div class="rd-modal__foot">' +
                     (isEdit ? '<button class="rd-btn js-tpl-del" data-id="' + _.escape(tpl.id) + '" style="color:#d22730;background:none;border:none">' + _.escape(self.i18n('templates.delete')) + '</button>' : '<span></span>') +
                '    <div style="display:flex;gap:10px">' +
                '      <button class="rd-btn rd-btn--ghost js-modal-close">' + _.escape(self.i18n('common.cancel')) + '</button>' +
                '      <button class="rd-btn rd-btn--primary js-tpl-save" data-id="' + _.escape(tpl.id || '') + '">' + _.escape(self.i18n('common.save')) + '</button>' +
                '    </div></div>' +
                '</div></div>'
            );
            (rdRoot() || $advMount).append($modal);
            $modal.data('type', curType);
        }

        // Условия шаблона как пары ключ/значение (из filters/pipeline).
        function tplCondChips(t) {
            var f = t.filters || {}, out = [];
            if (t.pipeline_id)         out.push({ k: self.i18n('templates.cond_pipeline') + ':', v: String(t.pipeline_id) });
            if (f.name_contains)       out.push({ k: self.i18n('templates.cond_name') + ':', v: f.name_contains });
            if (f.budget_min)          out.push({ k: self.i18n('templates.cond_budget') + ':', v: 'от ' + f.budget_min });
            if ((f.tags || []).length) out.push({ k: self.i18n('templates.cond_tags') + ':', v: f.tags.join(', ') });
            if (!out.length)           out.push({ k: self.i18n('templates.cond_any'), v: '' });
            return out;
        }

        function saveTemplate($modal) {
            var id   = $modal.find('.js-tpl-save').data('id');
            var type = $modal.data('type') || 'round_robin';
            var name = $.trim($modal.find('.js-tpl-name').val());
            if (!name) { notify(self.i18n('templates.name_required'), 'error'); return; }

            var managers = [];
            $modal.find('.js-member.rd-mchip--on').each(function() {
                var entry = { id: parseInt($(this).data('uid'), 10) };
                if (type === 'percent') entry.percent = parseInt($(this).find('.js-member-pct').val(), 10) || 0;
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
                toast(self.i18n('templates.saved'));
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
            var uid   = currentUserId();
            var $self = $mount.find('.js-self-online');
            if (uid == null) { $self.closest('.rd-online').hide(); return; }
            var online = !Object.prototype.hasOwnProperty.call(statuses, String(uid)) ||
                         !!(statuses[String(uid)] && statuses[String(uid)].online);
            $self.toggleClass('rd-tgl--on', online);
        }

        // $el может быть div-тумблером (advanced) или чекбоксом (панель настроек).
        function setStatus(userId, online, $el) {
            var uid = currentUserId();
            apiRequest('/api/status/' + parseInt(userId, 10), { online: !!online, actor_id: uid }, 'PUT')
                .done(function() { toast(self.i18n('status.saved')); })
                .fail(function() {
                    if ($el && $el.length) {
                        if ($el.is('input')) {
                            $el.prop('checked', !online);
                        } else {
                            $el.toggleClass('rd-tgl--on', !online);
                            var $lbl = $el.closest('.rd-stat').find('.js-stat-lbl');
                            $lbl.text(self.i18n(!online ? 'status.on' : 'status.off')).css('color', !online ? '#1a7a4a' : '#98a1b3');
                        }
                    }
                    notify(self.i18n('status.save_error'), 'error');
                    updateActiveCount($advMount);
                });
        }

        function bindAdvancedEvents($mount) {
            // Неймспейс .distadv защищает от дублей при пересоздании (init_once:false).
            $mount
                .off('.distadv')
                // ── навигация по сайдбару ──
                .on('click.distadv', '.rd-nav__i[data-p]', function() {
                    switchPanel($mount, $(this).data('p'));
                })
                // ── верхние вкладки (Шаблоны / Рабочее время) в разделе «Настройки» ──
                .on('click.distadv', '.rd-toptab[data-tab]', function() {
                    renderSettingsSurface($mount, $mount.find('.js-panel'), $(this).data('tab'));
                })
                // ── обновить ростер сотрудников ──
                .on('click.distadv', '.js-status-refresh', function() {
                    switchPanel($mount, 'status');
                })
                // ── фильтр по отделу ──
                .on('click.distadv', '.js-dept-btn', function(e) {
                    e.stopPropagation();
                    toggleDeptMenu($mount);
                })
                .on('click.distadv', '.js-dept-dd .rd-dd__i', function() {
                    var dept = $(this).data('dept') || '';
                    $mount.data('deptFilter', dept);
                    $mount.find('.js-dept-label').text(dept || self.i18n('status.all_depts'));
                    $mount.find('.rd-dd__menu').remove();
                    var st = $mount.data('statuses') || {};
                    renderStatusTableRd($mount.find('.js-status-body'), getUsers(), st, dept);
                    updateActiveCount($mount);
                })
                // ── тумблер статуса менеджера (div) ──
                .on('click.distadv', '.js-mgr-online', function() {
                    var $t  = $(this);
                    var on  = !$t.hasClass('rd-tgl--on');
                    $t.toggleClass('rd-tgl--on', on);
                    var $lbl = $t.closest('.rd-stat').find('.js-stat-lbl');
                    $lbl.text(self.i18n(on ? 'status.on' : 'status.off')).css('color', on ? '#1a7a4a' : '#98a1b3');
                    updateActiveCount($mount);
                    setStatus($t.data('uid'), on, $t);
                })
                // ── личный ONLINE-тумблер ──
                .on('click.distadv', '.js-self-online', function() {
                    var uid = currentUserId();
                    if (uid == null) return;
                    var $t = $(this);
                    var on = !$t.hasClass('rd-tgl--on');
                    $t.toggleClass('rd-tgl--on', on);
                    setStatus(uid, on, $t);
                    var $row = $mount.find('.js-mgr-online[data-uid="' + uid + '"]');
                    if ($row.length) {
                        $row.toggleClass('rd-tgl--on', on);
                        $row.closest('.rd-stat').find('.js-stat-lbl')
                            .text(self.i18n(on ? 'status.on' : 'status.off')).css('color', on ? '#1a7a4a' : '#98a1b3');
                        updateActiveCount($mount);
                    }
                })
                // ── выбор строк (визуально) ──
                .on('click.distadv', '.js-row-check', function() { $(this).toggleClass('rd-check--on'); })
                // ── шаблоны: карточки ──
                .on('click.distadv', '.js-tpl-add', function() { openTemplateModal(null); })
                .on('click.distadv', '.js-tpl-edit', function() { openTemplateModal(tplCache[$(this).data('id')]); })
                .on('click.distadv', '.js-tpl-toggle', function() { $(this).toggleClass('rd-tgl--on'); })
                // ── модалка редактора ──
                .on('click.distadv', '.js-modal-overlay', function(e) {
                    if (e.target === this) $(this).remove();
                })
                .on('click.distadv', '.js-modal-close', function() { $(this).closest('.rd-overlay').remove(); })
                .on('click.distadv', '.js-algo', function() {
                    var $modal = $(this).closest('.rd-overlay');
                    var type   = $(this).data('type');
                    $modal.data('type', type);
                    $modal.find('.js-algo').removeClass('rd-algo--on').find('.js-algo-check').hide();
                    $(this).addClass('rd-algo--on').find('.js-algo-check').show();
                    // Проценты у участников — только для типа percent.
                    $modal.find('.js-member-pct').toggle(type === 'percent');
                })
                .on('click.distadv', '.js-member', function(e) {
                    if ($(e.target).hasClass('js-member-pct')) return; // клик по полю % не переключает
                    var $m = $(this);
                    var on = !$m.hasClass('rd-mchip--on');
                    $m.toggleClass('rd-mchip--on', on);
                    $m.find('.js-member-check').toggle(on);
                })
                .on('click.distadv', '.js-tpl-save', function() { saveTemplate($(this).closest('.rd-overlay')); })
                .on('click.distadv', '.js-tpl-del', function() {
                    var id = $(this).data('id');
                    if (!id) return;
                    if (window.confirm(self.i18n('templates.confirm_delete'))) {
                        $(this).closest('.rd-overlay').remove();
                        deleteTemplate(id);
                    }
                });
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

            // Если открыта вкладка «Правила» — сохраняем и правила (чистым
            // объектом, без служебных полей amo вроде active/path/cdn_path).
            // Если правил в DOM нет (вкладка не открывалась) — ничего не шлём,
            // чтобы не затереть на сервере правила пустым списком.
            if ($container.data('rulesLoaded')) {
                saveRulesToBackend($container, null);
            }

            return true;
        };

        /** Digital Pipeline — панель настроек действия на этапе.
         *  amoCRM вызывает колбэк с jQuery-контейнером модалки действия.
         *  Менеджеры/условия задаются в правилах (Настройки → Распределение →
         *  Правила): правило само привязано к воронке+этапу, а действие на
         *  этапе лишь включает вебхук. Поэтому здесь — понятная подсказка,
         *  а не второй, дублирующий редактор. */
        self.dpSettings = function($el) {
            try {
                var $c = ($el && $el.jquery) ? $el : $($el);
                if ($c && $c.length && !$c.find('.dist-dp-hint').length) {
                    var code = (self.params && self.params.widget_code) || '';
                    var href = code ? '/settings/widgets/' + code + '/' : '/settings/widgets/';
                    $c.append(
                        '<div class="dist-dp-hint" style="font-size:13px;line-height:1.45;color:#3d4657;padding:10px 0">' +
                        '<b>Распределение сделок</b><br>' +
                        'Когда сделка попадает на этот этап, сервер выберет менеджера по правилу для этой воронки и этапа. ' +
                        'Менеджеры, метод и фильтры задаются в ' +
                        '<a href="' + href + '" target="_blank" style="color:#2b7de9">Настройки → Распределение сделок → Правила</a>. ' +
                        'Если правила для этапа нет — сделка останется без изменений (см. «Отчёт по распределениям»).' +
                        '</div>'
                    );
                }
            } catch (e) {}
            return true;
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
