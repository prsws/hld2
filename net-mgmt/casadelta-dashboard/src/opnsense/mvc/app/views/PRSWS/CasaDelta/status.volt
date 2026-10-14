{#
 # Casa Delta: host status page (contracts/ui-pages.md).
 # Uses only assets shipped with the OPNsense GUI. All values are inserted as text, never as HTML.
 #}
<script>
    $(document).ready(function () {
        const STATUS_URL = '/api/casadelta/status/get';
        const REFRESH_URL = '/api/casadelta/status/refresh';
        const SETTINGS_URL = '/ui/casadelta/settings';
        const DEFAULT_POLL_MS = 60000;
        const MIN_POLL_MS = 30000;
        const MAX_POLL_MS = 65000;
        const MIN_COOLDOWN_S = 5;

        const STATES = {
            up: {css: 'label-success', text: "{{ lang._('up') }}"},
            down: {css: 'label-danger', text: "{{ lang._('down') }}"},
            cannot_resolve: {css: 'label-warning', text: "{{ lang._('cannot resolve') }}"},
            pending: {css: 'label-default', text: "{{ lang._('pending') }}"}
        };
        const T = {
            summary: "{{ lang._('%s up out of %s hosts') }}",
            lastChecked: "{{ lang._('last checked') }}",
            stale: "{{ lang._('stale') }}",
            loadFailed: "{{ lang._('Status could not be loaded; retrying.') }}",
            refresh: "{{ lang._('Refresh now') }}",
            name: "{{ lang._('Name') }}",
            address: "{{ lang._('Address') }}",
            state: "{{ lang._('State') }}",
            latency: "{{ lang._('Latency') }}",
            checked: "{{ lang._('Last checked') }}"
        };

        let timer = null;
        let cooldownTimer = null;
        let panels = {};   // group name -> {panel, tbody, rows: {uuid -> tr}}

        function formatTime(iso) {
            if (!iso) {
                return '\u2014';
            }
            const d = new Date(iso);
            return isNaN(d.getTime()) ? '\u2014' : d.toLocaleTimeString();
        }

        function nextDelay(summary) {
            const next = summary && summary.next_check_at ? Date.parse(summary.next_check_at) : NaN;
            if (isNaN(next)) {
                return DEFAULT_POLL_MS;
            }
            return Math.min(MAX_POLL_MS, Math.max(MIN_POLL_MS, next - Date.now() + 2000));
        }

        function schedule(delay) {
            if (timer !== null) {
                clearTimeout(timer);
            }
            timer = setTimeout(poll, delay);
        }

        function showBanner(message) {
            $('#cd-banner').text(message).removeClass('hidden');
        }

        function hideBanner() {
            $('#cd-banner').addClass('hidden').text('');
        }

        function buildPanel(name) {
            const panel = $('<div class="panel panel-default"></div>');
            panel.append($('<div class="panel-heading"></div>').append($('<strong></strong>').text(name)));
            const table = $('<table class="table table-condensed table-striped" style="margin-bottom:0"></table>');
            const head = $('<tr></tr>');
            [T.name, T.address, T.state, T.latency, T.checked].forEach(function (title) {
                head.append($('<th></th>').text(title));
            });
            table.append($('<thead></thead>').append(head));
            const tbody = $('<tbody></tbody>');
            table.append(tbody);
            panel.append(table);
            return {panel: panel, tbody: tbody, rows: {}};
        }

        function buildRow() {
            const tr = $('<tr></tr>');
            for (let i = 0; i < 5; i++) {
                tr.append('<td></td>');
            }
            tr.find('td:eq(2)').append('<span class="label"></span> <small class="text-muted cd-stale"></small>');
            return tr;
        }

        function updateRow(tr, host) {
            const cells = tr.children('td');
            const info = STATES[host.state] || STATES.pending;
            $(cells[0]).text(host.name);
            $(cells[1]).text(host.address);
            const badge = $(cells[2]).find('span.label');
            badge.attr('class', 'label ' + info.css).text(info.text);
            badge.attr('title', host.error_message ? host.error_message : '');
            $(cells[2]).find('.cd-stale').text(host.stale ? '(' + T.stale + ')' : '');
            $(cells[3]).text(host.state === 'up' && host.latency_ms !== null ? host.latency_ms.toFixed(2) + ' ms' : '\u2014');
            $(cells[4]).text(formatTime(host.last_checked));
            tr.css('opacity', host.stale ? 0.55 : 1);
        }

        function render(data) {
            const summary = data.summary || {};
            const groups = data.groups || [];

            if (summary.subsystem_status === 'error') {
                showBanner(summary.subsystem_error || '');
            } else {
                hideBanner();
            }

            $('#cd-summary-text').text(T.summary.replace('%s', summary.up_hosts || 0).replace('%s', summary.total_hosts || 0));
            $('#cd-last-run').text(summary.last_run ? '(' + T.lastChecked + ' ' + formatTime(summary.last_run) + ')' : '');
            $('#cd-empty').toggleClass('hidden', (summary.total_hosts || 0) !== 0);

            const container = $('#cd-groups');
            const seenGroups = {};
            groups.forEach(function (group) {
                seenGroups[group.name] = true;
                if (!panels.hasOwnProperty(group.name)) {
                    panels[group.name] = buildPanel(group.name);
                }
                const entry = panels[group.name];
                container.append(entry.panel);   // appending re-orders existing panels

                const seenRows = {};
                group.hosts.forEach(function (host) {
                    seenRows[host.host_uuid] = true;
                    if (!entry.rows.hasOwnProperty(host.host_uuid)) {
                        entry.rows[host.host_uuid] = buildRow();
                    }
                    updateRow(entry.rows[host.host_uuid], host);
                    entry.tbody.append(entry.rows[host.host_uuid]);
                });
                Object.keys(entry.rows).forEach(function (uuid) {
                    if (!seenRows[uuid]) {
                        entry.rows[uuid].remove();
                        delete entry.rows[uuid];
                    }
                });
            });
            Object.keys(panels).forEach(function (name) {
                if (!seenGroups[name]) {
                    panels[name].panel.remove();
                    delete panels[name];
                }
            });
        }

        function poll() {
            ajaxGet(STATUS_URL, {}, function (data, status) {
                if (status !== 'success' || !data || !data.summary) {
                    showBanner(T.loadFailed);
                    schedule(DEFAULT_POLL_MS);
                    return;
                }
                render(data);
                schedule(nextDelay(data.summary));
            });
        }

        function startCooldown(seconds) {
            const button = $('#cd-refresh');
            let left = seconds;
            if (cooldownTimer !== null) {
                clearInterval(cooldownTimer);
            }
            button.prop('disabled', true);
            button.text(T.refresh + ' (' + left + ')');
            cooldownTimer = setInterval(function () {
                left -= 1;
                if (left <= 0) {
                    clearInterval(cooldownTimer);
                    cooldownTimer = null;
                    button.prop('disabled', false).text(T.refresh);
                } else {
                    button.text(T.refresh + ' (' + left + ')');
                }
            }, 1000);
        }

        $('#cd-refresh').on('click', function () {
            if ($(this).prop('disabled')) {
                return;
            }
            startCooldown(MIN_COOLDOWN_S);
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
            ajaxCall(REFRESH_URL, {}, function (data, status) {
                if (status !== 'success' || !data || !data.summary) {
                    showBanner(T.loadFailed);
                    schedule(DEFAULT_POLL_MS);
                    return;
                }
                render(data);
                const retry = data.refresh && data.refresh.retry_after_s ? data.refresh.retry_after_s : 0;
                startCooldown(Math.max(MIN_COOLDOWN_S, retry));
                schedule(nextDelay(data.summary));
            });
        });

        // The menu only lists pages the user may open, so its link tells us if the settings
        // privilege is present. This is a convenience hint only; the ACL layer enforces access.
        if ($('a[href="' + SETTINGS_URL + '"]').length > 0) {
            $('#cd-settings-link').removeClass('hidden');
        }

        poll();
    });
</script>

<div class="content-box">
    <div class="content-box-main" style="padding: 15px;">
        <div id="cd-banner" class="alert alert-warning hidden" role="alert"></div>

        <div class="row" style="margin-bottom: 15px;">
            <div class="col-xs-12">
                <h3 style="display: inline-block; margin: 0 15px 0 0;">
                    <span id="cd-summary-text"></span>
                </h3>
                <small id="cd-last-run" class="text-muted"></small>
                <button id="cd-refresh" type="button" class="btn btn-default pull-right">
                    <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh now') }}
                </button>
            </div>
        </div>

        <div id="cd-empty" class="alert alert-info hidden">
            {{ lang._('No monitored hosts configured or enabled.') }}
            <a id="cd-settings-link" class="hidden" href="/ui/casadelta/settings">{{ lang._('Open Host Settings') }}</a>
        </div>

        <div id="cd-groups"></div>
    </div>
</div>
