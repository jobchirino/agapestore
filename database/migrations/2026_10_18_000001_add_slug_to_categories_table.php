<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill: normalize existing names and resolve collisions (e.g. "Bisutería" / "Bisuteria").
        $used = [];

        foreach (DB::table('categories')->orderBy('id')->get() as $row) {
            $base = Str::slug($row->name) ?: 'categoria';
            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $used, true)) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            $used[] = $slug;

            DB::table('categories')->where('id', $row->id)->update(['slug' => $slug]);
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
