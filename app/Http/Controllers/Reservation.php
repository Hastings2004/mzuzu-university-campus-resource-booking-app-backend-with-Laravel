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




// namespace App\Models;

// use Carbon\Carbon;
// use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\Relations\BelongsTo;

// class Booking extends Model
// {
//     /** @use HasFactory<\Database\Factories\BookingFactory> */
//     use HasFactory;

//     use HasFactory;

//     protected $fillable = [
//         'booking_reference',
//         'resource_id',
//         'start_time',
//         'end_time',
//         'status',
//         'purpose',        
//         'cancelled_at',
//         'cancellation_reason'
//     ];

//     protected $casts = [
//         'start_time' => 'datetime',
//         'end_time' => 'datetime',
//         'cancelled_at' => 'datetime',
//     ];

//     const STATUS_APPROVED = 'approved';
//     const STATUS_PENDING = 'pending';
//     const STATUS_CANCELLED = 'cancelled';
//     const STATUS_EXPIRED = 'expired';
//     const STATUS_COMPLETED = 'completed';

//     // Relationship with User
//     public function user()
//     {
//         return $this->belongsTo(User::class);
//     }

//     // Relationship with Resource (if you have a resources table)
//     public function resource()
//     {
//         return $this->belongsTo(Resource::class);
//     }

//     // Check if booking is expired (based on end_time)
//     public function isExpired()
//     {
//         return Carbon::now()->greaterThan($this->end_time);
//     }

//     // Check if booking has started
//     public function hasStarted()
//     {
//         return Carbon::now()->greaterThanOrEqualTo($this->start_time);
//     }

//     // Check if booking can be cancelled
//     public function canBeCancelled()
//     {
//         return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PENDING]) 
//                && !$this->hasStarted() 
//                && !$this->isExpired();
//     }

//     // Scope for active bookings (approved/pending)
//     public function scopeActive($query)
//     {
//         return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PENDING]);
//     }

//     // Scope for non-expired bookings
//     public function scopeNotExpired($query)
//     {
//         return $query->where('end_time', '>', Carbon::now());
//     }

//     // Scope for not started bookings
//     public function scopeNotStarted($query)
//     {
//         return $query->where('start_time', '>', Carbon::now());
//     }

//     // Scope for cancellable bookings
//     public function scopeCancellable($query)
//     {
//         return $query->active()->notStarted()->notExpired();
//     }


// }

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon; // Ensure Carbon is imported
// use Illuminate\Database\Eloquent\SoftDeletes; // Consider if you want soft deletes

class Booking extends Model
{
    use HasFactory;
    // use SoftDeletes; // Uncomment if you add soft deletes

    protected $fillable = [
        'booking_reference',
        'user_id',
        'resource_id',
        'start_time',
        'end_time',
        'status',
        'purpose',
        'booking_type',     // New
        'priority',   // New
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Define booking statuses as constants for better readability and maintainability
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PREEMPTED = 'preempted'; // New status

    /**
     * Get the user that owns the booking.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the resource that the booking is for.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * Check if the booking has expired (start time is in the past)
     */
    public function isExpired(): bool
    {
        return $this->start_time->isPast();
    }

    /**
     * Check if the booking can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return !$this->isExpired() &&
               $this->status !== self::STATUS_CANCELLED &&
               $this->status !== self::STATUS_PREEMPTED; // Cannot cancel if already preempted
    }

    // Scopes for easier querying (optional, but good practice)
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('end_time', '>', Carbon::now());
    }
    public function scopeNotStarted($query)
    {
        return $query->where('start_time', '>', Carbon::now());
    }
    public function scopeCancellable($query)
    {
        return $query->where('status', self::STATUS_APPROVED)
                     ->orWhere('status', self::STATUS_PENDING)
                     ->where('start_time', '>', Carbon::now());
    }
    /**
     * Get the payment associated with the booking.
     */
    // This assumes a one-to-one relationship with Payment   

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
}




namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking; 
use App\Models\Resource; 
use App\Models\User;     
use Illuminate\Support\Facades\DB; 
use Carbon\Carbon; 

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Authorization Check
        if ($request->user()->user_type !== 'admin') {
            return response()->json(['message' => 'Unauthorized access. Only administrators can view this dashboard.'], 403);
        }

        // 2. Fetch Key Metrics (KPIs)
        $totalResources = Resource::count();
        $totalBookings = Booking::count();
        $totalUsers = User::count();

        // Calculate available resources more accurately if possible
        $availableResources = Resource::where('status', 'available')
            ->whereDoesntHave('bookings', function ($query) {
                $query->where('end_time', '>', Carbon::now())
                      ->whereIn('status', ['approved', 'pending']); // Consider pending if it affects availability
            })
            ->count();

        // 3. Fetch Chart Data

        // Bookings by Status
        $bookingsByStatus = Booking::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Resource Availability Overview (Example: available, maintenance)
        // This fetches counts for resources based on their primary status.
        $resourceAvailability = Resource::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                $name = ucfirst($item->status);
                // You can customize display names if needed
                if ($item->status === 'available') $name = 'Available';
                if ($item->status === 'maintenance') $name = 'Under Maintenance';
                // Add any other resource statuses you have (e.g., 'unavailable', 'decommissioned')
                return ['name' => $name, 'count' => $item->count];
            })
            ->toArray();

        // Adding 'Currently Booked' into resourceAvailability for a more complete picture.
        // This count is based on actual bookings, not a static resource status.
        $currentlyBookedResourcesCount = DB::table('bookings')
            ->where('end_time', '>', Carbon::now())
            ->where('start_time', '<', Carbon::now())
            ->where('status', 'approved')
            ->distinct('resource_id')
            ->count('resource_id');

        // Add 'Currently Booked' to the resourceAvailability array if it's not already there
        $foundBookedStatus = false;
        foreach ($resourceAvailability as &$statusItem) {
            if ($statusItem['name'] === 'Currently Booked') { // Check for existing 'Currently Booked'
                $statusItem['count'] += $currentlyBookedResourcesCount;
                $foundBookedStatus = true;
                break;
            }
        }
        if (!$foundBookedStatus) {
            $resourceAvailability[] = ['name' => 'Currently Booked', 'count' => $currentlyBookedResourcesCount];
        }


        // Top 5 Most Booked Resources
        $topBookedResources = DB::table('bookings')
            ->join('resources', 'bookings.resource_id', '=', 'resources.id')
            ->select('resources.id as resource_id', 'resources.name as resource_name', DB::raw('count(bookings.id) as total_bookings'))
            ->groupBy('resources.id', 'resources.name')
            ->orderByDesc('total_bookings')
            ->limit(5)
            ->get()
            ->toArray(); // Ensure it's an array for consistency

        // Monthly Booking Trends
        $monthlyBookings = Booking::select(
                DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                DB::raw('count(*) as total_bookings')
            )
            ->whereYear('start_time', Carbon::now()->year) // Get data for the current year
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();

        // **NEW: Resource Utilization (Total Booked Hours) Over Time**
        // This calculates the total duration of bookings per month.
        // Assumes 'start_time' and 'end_time' are datetime columns in your 'bookings' table.
        $resourceUtilizationMonthly = Booking::select(
                DB::raw("DATE_FORMAT(start_time, '%Y-%m') as month"),
                DB::raw("SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) as total_booked_hours")
            )
            ->whereYear('start_time', Carbon::now()->year) // For current year
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();

        // 4. Return the Data
        return response()->json([
            'total_resources' => $totalResources,
            'total_bookings' => $totalBookings,
            'total_users' => $totalUsers,
            'available_resources' => $availableResources,
            'bookings_by_status' => $bookingsByStatus,
            'resource_availability' => $resourceAvailability,
            'top_booked_resources' => $topBookedResources,
            'monthly_bookings' => $monthlyBookings,
            'resource_utilization_monthly' => $resourceUtilizationMonthly, // NEW DATA
        ]);
    }
} 

