<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            if (! Schema::hasColumn('properties', 'kitchens')) {
                $table->unsignedInteger('kitchens')->nullable()->after('bedrooms');
            }
        });

        Schema::table('property_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('property_images', 'room_type')) {
                $table->string('room_type')->nullable()->after('image_path');
            }

            if (! Schema::hasColumn('property_images', 'room_name')) {
                $table->string('room_name')->nullable()->after('room_type');
            }

            if (! Schema::hasColumn('property_images', 'alt_text')) {
                $table->string('alt_text')->nullable()->after('room_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_images', function (Blueprint $table): void {
            if (Schema::hasColumn('property_images', 'alt_text')) {
                $table->dropColumn('alt_text');
            }

            if (Schema::hasColumn('property_images', 'room_name')) {
                $table->dropColumn('room_name');
            }

            if (Schema::hasColumn('property_images', 'room_type')) {
                $table->dropColumn('room_type');
            }
        });

        Schema::table('properties', function (Blueprint $table): void {
            if (Schema::hasColumn('properties', 'kitchens')) {
                $table->dropColumn('kitchens');
            }
        });
    }
};
