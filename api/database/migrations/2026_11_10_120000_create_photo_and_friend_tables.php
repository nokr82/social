<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2차: 프로필 사진·하트·친구.
 * - 사진은 파일 저장소(로컬/R2·S3)에 두고 DB엔 경로만
 * - 하트 수는 사진에 비정규화(like_count) → 프로필 조회 때 COUNT 안 함
 * - 친구 관계는 양방향 2행으로 저장 → "내 친구" 조회가 WHERE user_id=? 한 번
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path', 200);                       // 저장소 안 경로 (URL 아님)
            $table->string('visibility', 10)->default('public'); // public | private (첫 장은 항상 public)
            $table->unsignedTinyInteger('sort_order');          // 0 = 대표사진
            $table->unsignedInteger('like_count')->default(0);  // 비공개로 바꿔도 유지
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
        });

        // 하트: 사진당 1인 1회 (PK가 중복을 DB 차원에서 막음)
        Schema::create('photo_likes', function (Blueprint $table) {
            $table->foreignId('photo_id')->constrained('profile_photos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['photo_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_id')->constrained('users')->cascadeOnDelete();
            // pending | accepted | rejected (보낸 사람에겐 rejected도 pending으로 보임)
            $table->string('status', 10)->default('pending');
            $table->string('message', 100)->nullable();         // 한마디
            $table->unsignedBigInteger('plaza_id')->nullable(); // 어디서 만났는지
            $table->timestamp('expires_at');                    // 보낸 뒤 7일
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['to_id', 'status']);
            $table->index(['from_id', 'status']);
        });

        Schema::create('friendships', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('plaza_id')->nullable(); // 처음 만난 광장 (설계서의 source)
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'friend_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
        Schema::dropIfExists('friend_requests');
        Schema::dropIfExists('photo_likes');
        Schema::dropIfExists('profile_photos');
    }
};
