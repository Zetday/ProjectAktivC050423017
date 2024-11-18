<?php

namespace App\Repositories\Contracts;

interface WorkshopRepositoryInterface
{
    public function getAllNewWorkshop();

    public function find($id);

    public function getPrice($workshopId);
}