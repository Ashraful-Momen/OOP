<?php

// ============================================================================
// 1. BASIC INTERFACE AND IMPLEMENTATION
// ============================================================================

// Define an interface for email service
interface EmailServiceInterface
{
    public function send(string $to, string $subject, string $message): bool;
}

// Concrete implementation for sending emails
class SMTPEmailService implements EmailServiceInterface
{
    private $host;
    private $port;

    public function __construct(string $host = 'smtp.gmail.com', int $port = 587)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function send(string $to, string $subject, string $message): bool
    {
        // Simulate sending email via SMTP
        echo "Sending email via SMTP ({$this->host}:{$this->port})\n";
        echo "To: {$to}\n";
        echo "Subject: {$subject}\n";
        echo "Message: {$message}\n";
        return true;
    }
}

// Alternative implementation for testing
class MockEmailService implements EmailServiceInterface
{
    public function send(string $to, string $subject, string $message): bool
    {
        echo "Mock: Email sent to {$to}\n";
        return true;
    }
}

// ============================================================================
// 2. SERVICE PROVIDER FOR BINDING DEPENDENCIES
// ============================================================================

// Create a service provider (app/Providers/EmailServiceProvider.php)
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Simple binding
        $this->app->bind(EmailServiceInterface::class, SMTPEmailService::class);

        // Singleton binding (one instance throughout request)
        $this->app->singleton('email.service', function ($app) {
            return new SMTPEmailService(
                config('mail.smtp.host', 'smtp.gmail.com'),
                config('mail.smtp.port', 587)
            );
        });

        // Contextual binding - different implementations for different classes
        $this->app->when(UserController::class)
                  ->needs(EmailServiceInterface::class)
                  ->give(SMTPEmailService::class);

        $this->app->when(TestController::class)
                  ->needs(EmailServiceInterface::class)
                  ->give(MockEmailService::class);
    }

    public function boot()
    {
        // Boot method for additional setup
    }
}

// Don't forget to register this provider in config/app.php:
// 'providers' => [
//     App\Providers\EmailServiceProvider::class,
// ],

// ============================================================================
// 3. REPOSITORY PATTERN WITH DI
// ============================================================================

// User model
class User extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['name', 'email'];
}

// Repository interface
interface UserRepositoryInterface
{
    public function findById(int $id): ?User;
    public function create(array $data): User;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}

// Eloquent repository implementation
class EloquentUserRepository implements UserRepositoryInterface
{
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(int $id, array $data): bool
    {
        return User::where('id', $id)->update($data);
    }

    public function delete(int $id): bool
    {
        return User::destroy($id);
    }
}

// ============================================================================
// 4. SERVICE CLASS WITH MULTIPLE DEPENDENCIES
// ============================================================================

class UserService
{
    private $userRepository;
    private $emailService;
    private $logger;

    // Constructor injection - Laravel automatically resolves dependencies
    public function __construct(
        UserRepositoryInterface $userRepository,
        EmailServiceInterface $emailService,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->userRepository = $userRepository;
        $this->emailService = $emailService;
        $this->logger = $logger;
    }

    public function createUser(array $userData): User
    {
        try {
            // Create user
            $user = $this->userRepository->create($userData);
            
            // Send welcome email
            $this->emailService->send(
                $user->email,
                'Welcome!',
                "Welcome {$user->name}! Your account has been created."
            );
            
            // Log the action
            $this->logger->info("User created: {$user->id}");
            
            return $user;
        } catch (\Exception $e) {
            $this->logger->error("Failed to create user: " . $e->getMessage());
            throw $e;
        }
    }

    public function sendNotification(int $userId, string $message): bool
    {
        $user = $this->userRepository->findById($userId);
        
        if (!$user) {
            return false;
        }

        return $this->emailService->send(
            $user->email,
            'Notification',
            $message
        );
    }
}

// ============================================================================
// 5. CONTROLLER WITH DEPENDENCY INJECTION
// ============================================================================

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    private $userService;

    // Constructor injection in controller
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users'
        ]);

        try {
            $user = $this->userService->createUser($request->only(['name', 'email']));
            
            return response()->json([
                'success' => true,
                'user' => $user,
                'message' => 'User created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user'
            ], 500);
        }
    }

    // Method injection - inject dependencies directly into methods
    public function sendNotification(
        Request $request, 
        int $userId,
        EmailServiceInterface $emailService  // Method injection
    ): JsonResponse {
        $message = $request->input('message', 'You have a new notification');
        
        $sent = $emailService->send(
            User::findOrFail($userId)->email,
            'Notification',
            $message
        );

        return response()->json(['sent' => $sent]);
    }
}

// ============================================================================
// 6. MANUAL RESOLUTION FROM SERVICE CONTAINER
// ============================================================================

// In a controller or service class
class ManualResolutionExample
{
    public function demonstrateManualResolution()
    {
        // Resolve from container manually
        $emailService = app(EmailServiceInterface::class);
        $userService = resolve(UserService::class);
        
        // Using make method
        $repository = app()->make(UserRepositoryInterface::class);
        
        // Resolve with parameters
        $customEmailService = app()->makeWith(SMTPEmailService::class, [
            'host' => 'custom.smtp.com',
            'port' => 465
        ]);
    }
}

// ============================================================================
// 7. BINDING IN SERVICE PROVIDER WITH DIFFERENT SCENARIOS
// ============================================================================

class AdvancedServiceProvider extends ServiceProvider
{
    public function register()
    {
        // 1. Simple binding
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);

        // 2. Singleton binding
        $this->app->singleton(EmailServiceInterface::class, function ($app) {
            return new SMTPEmailService();
        });

        // 3. Instance binding
        $this->app->instance('shared.config', ['key' => 'value']);

        // 4. Conditional binding
        if ($this->app->environment('testing')) {
            $this->app->bind(EmailServiceInterface::class, MockEmailService::class);
        }

        // 5. Tagged services
        $this->app->tag([
            SMTPEmailService::class,
            MockEmailService::class
        ], 'email.services');

        // 6. Extending existing bindings
        $this->app->extend(EmailServiceInterface::class, function ($service, $app) {
            // Add logging wrapper
            return new LoggingEmailDecorator($service, $app->make('log'));
        });
    }
}

// ============================================================================
// 8. FACADE PATTERN WITH DI
// ============================================================================

// Create a facade for easy access
namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class UserService extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \App\Services\UserService::class;
    }
}

// Usage: UserService::createUser(['name' => 'John', 'email' => 'john@example.com']);

// ============================================================================
// 9. ARTISAN COMMAND WITH DI
// ============================================================================

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SendNotificationsCommand extends Command
{
    protected $signature = 'notifications:send {userId}';
    protected $description = 'Send notification to a user';

    private $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    public function handle()
    {
        $userId = $this->argument('userId');
        $success = $this->userService->sendNotification($userId, 'Hello from Artisan!');
        
        if ($success) {
            $this->info('Notification sent successfully!');
        } else {
            $this->error('Failed to send notification.');
        }
    }
}

// ============================================================================
// 10. JOB WITH DEPENDENCY INJECTION
// ============================================================================

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendWelcomeEmailJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    private $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    // Dependencies are automatically injected into the handle method
    public function handle(
        UserRepositoryInterface $userRepository,
        EmailServiceInterface $emailService
    ) {
        $user = $userRepository->findById($this->userId);
        
        if ($user) {
            $emailService->send(
                $user->email,
                'Welcome!',
                "Welcome {$user->name}!"
            );
        }
    }
}

// ============================================================================
// 11. MIDDLEWARE WITH DI
// ============================================================================

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LogRequestsMiddleware
{
    private $logger;

    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Request $request, Closure $next)
    {
        $this->logger->info('Request received', [
            'url' => $request->url(),
            'method' => $request->method(),
            'ip' => $request->ip()
        ]);

        return $next($request);
    }
}

// ============================================================================
// 12. TESTING WITH DI
// ============================================================================

namespace Tests\Feature;

use Tests\TestCase;
use Mockery;

class UserServiceTest extends TestCase
{
    public function test_create_user_sends_welcome_email()
    {
        // Mock the dependencies
        $mockRepository = Mockery::mock(UserRepositoryInterface::class);
        $mockEmailService = Mockery::mock(EmailServiceInterface::class);
        $mockLogger = Mockery::mock(\Psr\Log\LoggerInterface::class);

        // Set up expectations
        $mockRepository->shouldReceive('create')
                      ->once()
                      ->with(['name' => 'John', 'email' => 'john@test.com'])
                      ->andReturn(new User(['id' => 1, 'name' => 'John', 'email' => 'john@test.com']));

        $mockEmailService->shouldReceive('send')
                        ->once()
                        ->with('john@test.com', 'Welcome!', Mockery::type('string'))
                        ->andReturn(true);

        $mockLogger->shouldReceive('info')
                  ->once();

        // Bind mocks to container
        $this->app->instance(UserRepositoryInterface::class, $mockRepository);
        $this->app->instance(EmailServiceInterface::class, $mockEmailService);
        $this->app->instance(\Psr\Log\LoggerInterface::class, $mockLogger);

        // Test the service
        $userService = $this->app->make(UserService::class);
        $user = $userService->createUser(['name' => 'John', 'email' => 'john@test.com']);

        $this->assertEquals('John', $user->name);
    }
}

// ============================================================================
// USAGE EXAMPLES AND BEST PRACTICES
// ============================================================================

/*
KEY CONCEPTS COVERED:

1. **Interface Segregation**: Always code to interfaces, not concrete classes
2. **Constructor Injection**: Primary method of DI in Laravel
3. **Method Injection**: Inject dependencies directly into controller methods
4. **Service Container**: Laravel's IoC container manages all dependencies
5. **Service Providers**: Register bindings and configure services
6. **Contextual Binding**: Different implementations for different contexts
7. **Singleton Pattern**: Single instance throughout request lifecycle
8. **Repository Pattern**: Abstract data access layer
9. **Facades**: Static-like interface to container services
10. **Testing**: Easy mocking and testing with DI

BEST PRACTICES:

1. Always use interfaces for dependencies
2. Keep constructors simple - just assign dependencies
3. Use service providers for binding configuration
4. Leverage contextual binding for different environments
5. Use singletons for expensive-to-create objects
6. Mock dependencies in tests
7. Keep services focused on single responsibility
8. Use type hints for automatic resolution

LARAVEL SPECIFIC FEATURES:

- Automatic resolution based on type hints
- Service providers for configuration
- Contextual binding for different scenarios
- Method injection in controllers
- Container tags for grouping services
- Extending existing bindings
- Integration with queues, commands, and middleware
*/
