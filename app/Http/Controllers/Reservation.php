<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class Reservation extends Controller
{
    //
    /**
     * Display a listing of the reservations.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Logic to display reservations


    }

    /**
     * Show the form for creating a new reservation.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Logic to show form for creating a new reservation
    }

    public function store(Request $request)
    {
        // Logic to store a new reservation
        $request->validate([
            'resource_id' => 'required|exists:resources,id',
            'start_time' => 'required|date|after_or_equal:now',
            'end_time' => 'required|date|after:start_time',
            'purpose' => 'required|string|max:500',
        ]);
       
        $booking = $request->user()->bookings()->create([
            'booking_reference' => 'BR-' . strtoupper(uniqid()),
            'resource_id' => $request->resource_id,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'status' => Booking::STATUS_PENDING, // Default status
            'purpose' => $request->purpose,
        ]);

        return response()->json([
            'message' => 'Reservation created successfully',
            'booking' => $booking
        ], 201);
    }
}
?>
<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse; // Import JsonResponse
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth; // Use Illuminate\Support\Facades\Auth for Auth::user() etc.

class AuthController extends Controller
{
    // Register a new user
    public function register(Request $request): JsonResponse
    {
        $field = $request->validate([
            'first_name' => 'required|alpha|max:255',
            'last_name' => 'required|alpha|max:255',
            'user_type' => 'required|string|in:admin,staff,student', // Ensure valid user types
            'email' => 'required|max:255|email|unique:users',
            'password' => 'required|confirmed|min:6',
        ]);

        // Hash the password before creating the user
        $user = User::create([
            'first_name' => $field['first_name'],
            'last_name' => $field['last_name'],
            'email' => $field['email'],
            'password' => Hash::make($field['password']), // Hash password
        ]);

        $roleName = $request->input('user_type');
        $role = Role::where('name', $roleName)->first();

        if ($role) {
            $user->roles()->attach($role->id);
        } else {
            // Rollback user creation if role not found or handle as error
            $user->delete(); // Delete user if role assignment fails
            return response()->json(['message' => "Role '{$roleName}' not found."], 400);
        }

        // Trigger the Registered event to send the email verification notification
        event(new Registered($user));

        // Note: We are NOT logging the user in immediately or returning a token here.
        // The user must verify their email first.
        // We return basic user info and a message.

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'user_type' => $user->user_type, // This uses the accessor on the User model
                'email_verified_at' => $user->email_verified_at, // Will be null initially
            ],
            'success' => true,
            'message' => 'Registration successful! Please check your email to verify your account.'
        ], 201);
    }

    // Login a user
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|max:255|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        // --- NEW: Check if email is verified ---
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is not verified. Please check your inbox for a verification link.',
                'email_verified' => false, // Custom flag for frontend
            ], 403); // Forbidden status for unverified email
        }
        // --- END NEW ---

        // Load user with roles and user_type accessor
        $user->load('roles');

        $token = $user->createToken($user->first_name)->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'user_type' => $user->user_type, // This uses the accessor on the User model
                'email_verified_at' => $user->email_verified_at, // Send verification status
            ],
            'success' => true,
            'token' => $token
        ], 200);
    }

    // Send verification email (for resending)
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        $user = Auth::user(); // Get the authenticated user

        if (!$user) {
            return response()->json(['message' => 'Unauthorized: User not authenticated.'], 401);
        }

        // if ($user->hasVerifiedEmail()) {
        //     return response()->json(['message' => 'Email already verified.'], 200);
        // }

        // $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent! Please check your inbox.'], 200);
    }

    /**
     * Resend the email verification notification for a given email (public access).
     * This endpoint does NOT require authentication.
     */
    public function resendVerificationPublic(Request $request): JsonResponse
    {
        // Validate that the email is present and exists in the users table
        $request->validate(['email' => 'required|email|exists:users,email']);

        $user = User::where('email', $request->email)->first();

        // This check should technically be redundant due to 'exists:users,email' validation,
        // but it's a good safeguard.
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // If the email is already verified, inform the user
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        // Send the verification notification
        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent! Please check your inbox.'], 200);
    }

    // Handle email verification (from the link clicked in email)
    // IMPORTANT: This route is typically hit directly by the browser from the email link.
    // For an API-only approach, you might adjust this, but for simplicity, we'll keep it as Laravel's
    // standard `verify` route logic, which uses `EmailVerificationRequest`.
    // Laravel's `EmailVerificationRequest` will automatically handle marking the email as verified
    // and then redirecting. We will just return a JSON response here.
    public function verifyEmail(Request $request): JsonResponse
    {
        // Use Laravel's built-in EmailVerificationRequest logic
        // This will automatically find the user, validate the hash, and mark email as verified.
        // We override the typical redirect to return JSON.
        try {
            $user = User::findOrFail($request->route('id'));

            if (! hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
                throw new \Exception('Invalid verification hash.');
            }

            if ($user->hasVerifiedEmail()) {
                return response()->json(['message' => 'Email already verified.'], 200);
            }

            if ($user->markEmailAsVerified()) {
                event(new \Illuminate\Auth\Events\Verified($user)); // Fire the Verified event
                return response()->json(['message' => 'Email verified successfully! You can now log in.'], 200);
            }

            return response()->json(['message' => 'Email verification failed.'], 500);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Email verification failed due to an invalid link or other error.'], 400);
        }
    }


    // Logout a user
    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'You have been successfully logged out.'
        ], 200);
    }
}
