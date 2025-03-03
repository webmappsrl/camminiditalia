<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('start_url');
            $table->dropColumn('show_edit_link');
            $table->dropColumn('skip_route_index_download');
            $table->dropColumn('table_details_show_gpx_download');
            $table->dropColumn('table_details_show_kml_download');
            $table->dropColumn('table_details_show_related_poi');
            $table->dropColumn('enable_routing');
            $table->dropColumn('feature_image');
            $table->dropColumn('offline_enable');
            $table->dropColumn('offline_force_auth');
            $table->dropColumn('table_details_show_duration_forward');
            $table->dropColumn('table_details_show_duration_backward');
            $table->dropColumn('table_details_show_distance');
            $table->dropColumn('table_details_show_ascent');
            $table->dropColumn('table_details_show_descent');
            $table->dropColumn('table_details_show_ele_max');
            $table->dropColumn('table_details_show_ele_min');
            $table->dropColumn('table_details_show_ele_from');
            $table->dropColumn('table_details_show_ele_to');
            $table->dropColumn('table_details_show_scale');
            $table->dropColumn('table_details_show_cai_scale');
            $table->dropColumn('table_details_show_mtb_scale');
            $table->dropColumn('table_details_show_ref');
            $table->dropColumn('table_details_show_surface');
            $table->dropColumn('table_details_show_geojson_download');
            $table->dropColumn('table_details_show_shapefile_download');
            $table->dropColumn('icon_notify');
            $table->dropColumn('tracks_on_payment');
            $table->dropColumn('filter_poi_type');
            $table->dropColumn('show_favorites');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('start_url')->default('/main/explore');
            $table->boolean('show_edit_link')->default(false);
            $table->boolean('skip_route_index_download')->default(true);
            $table->boolean('table_details_show_gpx_download')->default(false);
            $table->boolean('table_details_show_kml_download')->default(false);
            $table->boolean('table_details_show_related_poi')->default(false);
            $table->boolean('enable_routing')->default(false);
            $table->string('feature_image')->nullable();
            $table->boolean('offline_enable')->default(false);
            $table->boolean('offline_force_auth')->default(false);
            $table->boolean('table_details_show_duration_forward')->default(true);
            $table->boolean('table_details_show_duration_backward')->default(false);
            $table->boolean('table_details_show_distance')->default(true);
            $table->boolean('table_details_show_ascent')->default(true);
            $table->boolean('table_details_show_descent')->default(true);
            $table->boolean('table_details_show_ele_max')->default(true);
            $table->boolean('table_details_show_ele_min')->default(true);
            $table->boolean('table_details_show_ele_from')->default(false);
            $table->boolean('table_details_show_ele_to')->default(false);
            $table->boolean('table_details_show_scale')->default(true);
            $table->boolean('table_details_show_cai_scale')->default(false);
            $table->boolean('table_details_show_mtb_scale')->default(false);
            $table->boolean('table_details_show_ref')->default(true);
            $table->boolean('table_details_show_surface')->default(false);
            $table->boolean('table_details_show_geojson_download')->default(false);
            $table->boolean('table_details_show_shapefile_download')->default(false);
            $table->string('icon_notify')->nullable();
            $table->boolean('tracks_on_payment')->default(false);
            $table->boolean('filter_poi_type')->nullable();
            $table->boolean('show_favorites')->default(true);
        });
    }
};
