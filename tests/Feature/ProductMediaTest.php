<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'slug' => Str::uuid()->toString(),
        ]);
        $this->user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'username' => Str::uuid()->toString(),
        ]);
        $this->actingAs($this->user);
    }

    public function test_product_images_can_be_uploaded_when_creating_and_updating_products(): void
    {
        $this->post('/api/products', [
            'name' => 'Tea',
            'sale_price' => 5,
            'cost_price' => 2,
            'image_file' => UploadedFile::fake()->image('tea.png'),
        ])->assertCreated();

        $product = Product::firstOrFail();
        $oldPath = parse_url($product->image, PHP_URL_PATH);
        $oldPath = substr($oldPath, strpos($oldPath, '/storage/') + 9);
        Storage::disk('public')->assertExists($oldPath);

        $this->post('/api/products/' . $product->id, [
            '_method' => 'PUT',
            'image_file' => UploadedFile::fake()->image('tea-updated.png'),
        ])->assertOk();

        $product->refresh();
        $newPath = parse_url($product->image, PHP_URL_PATH);
        $newPath = substr($newPath, strpos($newPath, '/storage/') + 9);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_authenticated_user_can_upload_a_profile_image(): void
    {
        $this->post('/api/auth/avatar', [
            'image' => UploadedFile::fake()->image('avatar.png'),
        ])->assertOk()
            ->assertJsonPath('user.id', $this->user->id);

        $this->user->refresh();
        $path = parse_url($this->user->avatar, PHP_URL_PATH);
        $path = substr($path, strpos($path, '/storage/') + 9);
        Storage::disk('public')->assertExists($path);
    }
}
