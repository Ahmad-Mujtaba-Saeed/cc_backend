<?php

namespace Modules\User\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\User\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function getUser(Request $request)
    {
        $user = $request->user();
        return response()->json($user);
    }

    public function ProfileSettings(Request $request){
        $request->validate([
            "name" => "sometimes|string|max:100|min:3",
            "phone" => "sometimes|string|max:200",
            "email" => "sometimes|email|unique:users,email," . auth()->id(),
            "profile_img" => "sometimes|image|mimes:jpeg,png,jpg,gif|max:2048",
            "bio" => "nullable|string|max:300",
            "lang" => "sometimes|string|max:10",
            "time_zone" => "sometimes|string|max:50",
            "email_notif" => "sometimes|boolean",
            "push_notif" => "sometimes|boolean"
        ]);

        $user = Auth::user();
        
        // Update basic info
        if ($request->has('name')) {
            $user->name = $request->name;
        }

        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }
        
        if ($request->has('bio')) {
            $user->bio = $request->bio;
        }
        
        if ($request->has('email') && $request->email !== $user->email) {
            $user->email = $request->email;
            // You might want to implement email verification here
        }

        // Update language preference
        if ($request->has('lang')) {
            $user->lang = $request->lang;
        }

        // Update timezone
        if ($request->has('time_zone')) {
            $user->time_zone = $request->time_zone;
        }

        // Update email notification preference
        if ($request->has('email_notif')) {
            $user->email_notif = $request->email_notif;
        }

        // Update push notification preference
        if ($request->has('push_notif')) {
            $user->push_notif = $request->push_notif;
        }

        // Handle profile image upload
        if ($request->hasFile('profile_img')) {
            // Delete old profile image if exists
            if ($user->profile_img) {
                Storage::disk('public')->delete($user->profile_img);
            }
            
            // Store the file in storage/app/public/profiles
            $path = $request->file('profile_img')->store('profiles', 'public');
            
            // Store the path in the database
            $user->profile_img = $path;
        }

        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Profile updated successfully',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Change the authenticated user's password by verifying their current one.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|max:18|confirmed|different:current_password',
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Your current password is incorrect.',
                'errors' => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Invalidate other sessions: revoke every token except the one in use.
        $currentTokenId = optional($user->currentAccessToken())->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password updated successfully.',
        ]);
    }
}