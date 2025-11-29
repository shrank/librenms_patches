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
            <table id="mempool" class="table table-hover table-condensed"
                   data-url="{{ route('table.mempools') }}">
                <thead>
                <tr>
                    <th data-column-id="hostname">Device</th>
                    <th data-column-id="mempool_descr">{{ $view == 'graphs' ? '' : __('Memory') }}</th>
                    <th data-column-id="graph" data-sortable="false" data-searchable="false"></th>
                    <th data-column-id="mempool_used" data-searchable="false">{{ $view == 'graphs' ? '' : __('Used') }}</th>
                    <th data-column-id="mempool_perc" data-searchable="false">{{ $view == 'graphs' ? '' : __('Usage') }}</th>
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
                    <option value="warning">Warning</option>\
                    <option selected value="">All</option>\
                </select>\
            </div>'
            );

            $("#sensor-status-dropdown").on("change", function() {
                  $("#mempool").bootgrid('reload');
              });

        });

        var grid = $("#mempool").bootgrid({
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
