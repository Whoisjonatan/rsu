<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserTypeRequest;
use App\Models\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** W06 Funciones del personal. */
class UserTypeController extends Controller
{
    public function index(): View
    {
        return view('people.usertypes', [
            'types' => UserType::withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function store(UserTypeRequest $request): RedirectResponse
    {
        $type = UserType::create($request->typeData());

        return back()->with('success', "Función \"{$type->name}\" registrada.");
    }

    public function update(UserTypeRequest $request, UserType $usertype): RedirectResponse
    {
        $usertype->update($request->typeData($usertype));

        return back()->with('success', "Función \"{$usertype->name}\" actualizada.");
    }

    public function destroy(UserType $usertype): RedirectResponse
    {
        if ($blocker = $usertype->deletionBlocker()) {
            return back()->with('error', $blocker);
        }

        $usertype->delete();

        return back()->with('success', "Función \"{$usertype->name}\" eliminada.");
    }
}
