{#
 # Casa Delta: host settings page (contracts/ui-pages.md). UIBootgrid plus base_dialog only.
 # No Apply button is needed: changes take effect at the next status poll.
 #}
<script>
    $(document).ready(function () {
        $("#grid-hosts").UIBootgrid({
            search: '/api/casadelta/settings/searchHost/',
            get: '/api/casadelta/settings/getHost/',
            set: '/api/casadelta/settings/setHost/',
            add: '/api/casadelta/settings/addHost/',
            del: '/api/casadelta/settings/delHost/',
            toggle: '/api/casadelta/settings/toggleHost/'
        });
    });
</script>

<div class="content-box">
    <div class="content-box-main" style="padding: 15px;">
        <div class="alert alert-info">
            {{ lang._('Disabled hosts are kept here but are not checked and are not shown on the status page. Changes take effect at the next status refresh.') }}
        </div>
        <table id="grid-hosts" class="table table-condensed table-hover table-striped" data-editDialog="DialogHost" data-editAlert="HostChangeMessage">
            <thead>
                <tr>
                    <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                    <th data-column-id="enabled" data-width="6em" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                    <th data-column-id="group" data-type="string">{{ lang._('Group') }}</th>
                    <th data-column-id="sort_order" data-width="8em" data-type="numeric">{{ lang._('Sort order') }}</th>
                    <th data-column-id="name" data-type="string">{{ lang._('Name') }}</th>
                    <th data-column-id="address" data-type="string">{{ lang._('Address') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                    <th data-column-id="commands" data-width="9em" data-formatter="commands" data-sortable="false">{{ lang._('Commands') }}</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td>
                        <button data-action="add" type="button" class="btn btn-xs btn-primary"><span class="fa fa-fw fa-plus"></span></button>
                        <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default"><span class="fa fa-fw fa-trash-o"></span></button>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{ partial("layout_partials/base_dialog", ['fields': formDialogHost, 'id': 'DialogHost', 'label': lang._('Edit host')]) }}
