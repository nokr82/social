<?php

namespace App\Filament\Resources\UserSanctions\Pages;

use App\Filament\Resources\UserSanctions\UserSanctionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageUserSanctions extends ManageRecords
{
    protected static string $resource = UserSanctionResource::class;
}
