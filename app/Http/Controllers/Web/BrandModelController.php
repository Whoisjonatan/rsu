<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandModelRequest;
use App\Models\BrandModel;
use Illuminate\Http\RedirectResponse;

/** Modelos (pestaña de W03). */
class BrandModelController extends Controller
{
    public function store(BrandModelRequest $request): RedirectResponse
    {
        $model = BrandModel::create($request->validated());

        return back()->with('success', "Modelo \"{$model->name}\" registrado.");
    }

    public function update(BrandModelRequest $request, BrandModel $brandmodel): RedirectResponse
    {
        $brandmodel->update($request->validated());

        return back()->with('success', "Modelo \"{$brandmodel->name}\" actualizado.");
    }

    public function destroy(BrandModel $brandmodel): RedirectResponse
    {
        if ($blocker = $brandmodel->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $brandmodel->delete();

        return back()->with('success', "Modelo \"{$brandmodel->name}\" eliminado.");
    }
}
