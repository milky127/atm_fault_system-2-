<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../admin/dashboard.php");
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>ATM Fault Management System</title>

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- Custom CSS -->
    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

</head>

<body class="login-page">

<div class="container-fluid min-vh-100">

    <div class="row min-vh-100">

        <!-- LEFT SIDE -->
        <div class="col-lg-7 d-none d-lg-flex login-brand-section">

            <div class="brand-content">

                <div class="brand-logo-wrapper">

                    <img
                        src="../assets/images/cbe-logo.png"
                        alt="Commercial Bank of Ethiopia"
                        class="brand-logo"
                    >

                </div>

                <h1 class="display-5 fw-bold mt-4">
                    ATM Fault Reporting
                </h1>

                <h2 class="fw-light">
                    & Tracking System
                </h2>

                <div class="gold-line my-4"></div>

                <p class="lead">
                    Centralized ATM fault reporting,
                    incident tracking and maintenance
                    management.
                </p>

                <div class="row mt-5 g-3">

                    <div class="col-md-4">

                        <div class="feature-box">

                            <i class="bi bi-exclamation-triangle"></i>

                            <span>
                                Fault Reporting
                            </span>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="feature-box">

                            <i class="bi bi-activity"></i>

                            <span>
                                Incident Tracking
                            </span>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="feature-box">

                            <i class="bi bi-tools"></i>

                            <span>
                                Maintenance
                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- RIGHT SIDE -->
        <div class="col-lg-5 login-form-section">

            <div class="login-card">

                <div class="text-center mb-4">

                    <div class="mobile-logo">

                        <img
                            src="../assets/images/cbe-logo.png"
                            alt="CBE Logo"
                        >

                    </div>

                    <h3 class="fw-bold purple-text mt-3">
                        Welcome Back
                    </h3>

                    <p class="text-muted">
                        Sign in to your support dashboard
                    </p>

                </div>


                <?php if (isset($_GET['error'])): ?>

                    <div
                        class="alert alert-danger d-flex align-items-center"
                        role="alert"
                    >

                        <i class="bi bi-exclamation-circle-fill me-2"></i>

                        <div>
                            Invalid username or password.
                        </div>

                    </div>

                <?php endif; ?>


                <form
                    action="authenticate.php"
                    method="POST"
                >

                    <!-- USERNAME -->

                    <div class="mb-3">

                        <label
                            for="username"
                            class="form-label fw-semibold"
                        >
                            Username
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">

                                <i class="bi bi-person"></i>

                            </span>

                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                required
                                autocomplete="username"
                            >

                        </div>

                    </div>


                    <!-- PASSWORD -->

                    <div class="mb-4">

                        <label
                            for="password"
                            class="form-label fw-semibold"
                        >
                            Password
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">

                                <i class="bi bi-lock"></i>

                            </span>

                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                required
                                autocomplete="current-password"
                            >

                            <button
                                type="button"
                                class="btn password-toggle"
                                onclick="togglePassword()"
                            >

                                <i
                                    class="bi bi-eye"
                                    id="passwordIcon"
                                ></i>

                            </button>

                        </div>

                    </div>


                    <!-- LOGIN -->

                    <button
                        type="submit"
                        class="btn login-button w-100"
                    >

                        <i class="bi bi-box-arrow-in-right me-2"></i>

                        Sign In

                    </button>

                </form>


                <div class="text-center mt-4">

                    <small class="text-muted">

                        ATM Support Management System

                    </small>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

function togglePassword() {

    const password =
        document.getElementById("password");

    const icon =
        document.getElementById("passwordIcon");

    if (password.type === "password") {

        password.type = "text";

        icon.classList.remove("bi-eye");

        icon.classList.add("bi-eye-slash");

    } else {

        password.type = "password";

        icon.classList.remove("bi-eye-slash");

        icon.classList.add("bi-eye");

    }

}

</script>

</body>

</html>