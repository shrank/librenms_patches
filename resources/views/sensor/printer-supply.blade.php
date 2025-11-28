@extends('layouts.librenmsv1')

@section('title', $title)

@section('content')
    <div class="panel panel-default panel-condensed">
        <div class="panel-heading">
            <div class="row" style="padding:0px 10px 0px 10px;">
                <div class="pull-left">
                    <x-option-bar border="none" name="Health" :options="$metrics" :selected="$metric"></x-option-bar>
                </div>

                <div class="pull-right">
                    <x-option-bar border="none" :options="$views" :selected="$view"></x-option-bar>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table id="printer" class="table table-hover table-condensed"
                   data-url="{{ route('table.printer-supply') }}">
                <thead>
                <tr>
                    <th data-column-id="device_hostname">{{ __('Device') }}</th>
                    <th data-column-id="supply_descr">{{ __('Descr') }}</th>
                    <th data-column-id="supply_type">{{ __('Type') }}</th>
                    <th data-column-id="graph" data-sortable="false" data-searchable="false"></th>
                    <th data-column-id="supply_current" data-searchable="false">{{ __('Supply') }}</th>
                </tr>
                </thead>
            </table>
        </div>
    </div>
    <script>
        $(document).ready(function(){

            $('.actionBar').append('<div class="pull-left">\
                <label for="sensor-status-dropdown" class="control-label">{{ __('Status') }}:</label>\
                <select class="form-control" name="sensor-status" id="sensor-status-dropdown">\
                    <option value="alert">Alert</option>\
                    <option value="error">Error</option>\
                    <option value="warning">Warning</option>\
                    <option selected value="">All</option>\
                </select>\
            </div>'
            );

            $("#sensor-status-dropdown").on("change", function() {
                  $("#printer").bootgrid('reload');
              });

        });

        var grid = $("#printer").bootgrid({
            ajax: true,
            rowCount: [50, 100, 250, -1],
            post: function ()
            {
                return {
                    view: '{{ $view }}',
                    status: $("#sensor-status-dropdown").val(),
                };
            }
        });
    </script>

@endsection
