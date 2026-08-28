<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('nombre_comercial');
        });

        // Backfill: tenants creados antes de esta migración no tienen slug. Se deriva de
        // nombre_comercial, con sufijo numérico si hay choque de unicidad.
        Tenant::whereNull('slug')->get()->each(function (Tenant $tenant) {
            $base = Str::slug($tenant->nombre_comercial) ?: "tenant-{$tenant->id_tenant}";
            $slug = $base;
            $suffix = 2;

            while (Tenant::where('slug', $slug)->where('id_tenant', '!=', $tenant->id_tenant)->exists()) {
                $slug = "{$base}-{$suffix}";
                $suffix++;
            }

            $tenant->slug = $slug;
            $tenant->save();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
