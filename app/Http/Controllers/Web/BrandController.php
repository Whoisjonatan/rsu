<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;

/**
 * Marcas (pestaña de W03). El logo viaja en el mismo formulario que la ficha:
 * se elige, se ve la vista previa y se guarda todo junto.
 */
class BrandController extends Controller
{
    public function store(BrandRequest $request): RedirectResponse
    {
        $brand = Brand::create($request->brandData());

        if ($request->hasFile('logo')) {
            $brand->replaceLogo($request->file('logo'));
        }

        return back()->with('success', "Marca \"{$brand->name}\" registrada.");
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->brandData());

        if ($request->hasFile('logo')) {
            $brand->replaceLogo($request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $brand->replaceLogo(null);
        }

        return back()->with('success', "Marca \"{$brand->name}\" actualizada.");
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        if ($blocker = $brand->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $brand->deleteWithLogo();

        return back()->with('success', "Marca \"{$brand->name}\" eliminada.");
    }
}
