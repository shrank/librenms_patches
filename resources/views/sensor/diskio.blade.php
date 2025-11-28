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
            <table id="storage" class="table table-hover table-condensed diskio">
                <thead>
                <tr>
                    <th data-column-id="device_hostname">{{ __('Device') }}</th>
                    <th data-column-id="diskio_descr">{{ __('Storage') }}</th>
                    <th data-column-id="bits_graph" data-sortable="false" data-searchable="false">{{ __('Bits') }}</th>
                    <th data-column-id="ops_graph" data-sortable="false" data-searchable="false">{{ __('Ops') }}</th>
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
                  $("#storage").bootgrid('reload');
              });

        });

        var grid = $("#storage").bootgrid({
            ajax: true,
            rowCount: [50, 100, 250, -1],
            post: function ()
            {
                return {
                    view: '{{ $view }}',
                    status: $("#sensor-status-dropdown").val(),
                };
            },
            url: "<?php echo route('table.diskio') ?>"
        });
    </script>

@endsection
