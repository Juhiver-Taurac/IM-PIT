<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserCredsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        
    User::create([
        'name' => 'Georgee',
        'email' => 'georgeeominguito@gmail.com',
        'password' => bcrypt('admin'), 
        'email_verified_at' => '2024-06-19', 
        'role' => 'admin',
    ]);
    }
}
