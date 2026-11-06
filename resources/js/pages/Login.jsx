import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import Brand from '../components/Brand';
import Icon from '../components/Icon';

export default function Login({ demoAvailable }) {
    const [registering, setRegistering] = useState(false);
    const [enteringDemo, setEnteringDemo] = useState(false);
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function handleSubmit(event) {
        event.preventDefault();
        form.post(registering ? '/register' : '/login', {
            onSuccess: (page) => {
                if (page.props.signedIn) {
                    window.location.hash = page.props.mailbox.email
                        ? 'Dashboard'
                        : 'ConnectGmail';
                }
            },
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    return (
        <div className="login">
            <div className="login-story">
                <Brand showAi />
                <div className="story-main">
                    <div className="eyebrow">
                        A calmer way to handle client requests
                    </div>
                    <h1>
                        From inbox to answer, <em>with confidence.</em>
                    </h1>
                    <p>
                        Bring client emails, financial records, and supporting
                        documents into one thoughtful review workflow.
                    </p>
                    <div className="story-card">
                        <div className="story-card-top">
                            <Icon name="mail" /> Incoming request <b>·</b>{' '}
                            Northstar Trading
                        </div>
                        <p>
                            “Could you send our Q3 expense breakdown and
                            supporting records?”
                        </p>
                        <span className="mini-tag">
                            <Icon name="check" size={13} /> Data matched · Draft
                            ready for review
                        </span>
                    </div>
                </div>
                <div className="story-foot">
                    Designed for the people behind the numbers. ©{' '}
                    {new Date().getFullYear()} Ledgerly
                </div>
            </div>
            <main className="login-form-wrap">
                <form className="login-form" onSubmit={handleSubmit}>
                    <div className="eyebrow">
                        {registering ? 'Your next chapter' : 'Welcome back'}
                    </div>
                    <h2>
                        {registering
                            ? 'Create your workspace'
                            : 'Sign in to your workspace'}
                    </h2>
                    <p className="sub">
                        Review client requests and keep every response grounded
                        in your records.
                    </p>
                    {registering && (
                        <div className="field">
                            <label htmlFor="name">Your name</label>
                            <input
                                id="name"
                                required
                                autoComplete="name"
                                maxLength={100}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                placeholder="Your full name"
                            />
                        </div>
                    )}
                    <div className="field">
                        <label htmlFor="email">Work email</label>
                        <input
                            id="email"
                            type="email"
                            required
                            autoComplete="username"
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                            placeholder="you@firm.com"
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            required
                            autoComplete={
                                registering
                                    ? 'new-password'
                                    : 'current-password'
                            }
                            minLength={registering ? 10 : undefined}
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                            placeholder={
                                registering
                                    ? 'At least 10 characters'
                                    : 'Enter your password'
                            }
                        />
                    </div>
                    {registering && (
                        <div className="field">
                            <label htmlFor="confirm-password">
                                Confirm password
                            </label>
                            <input
                                id="confirm-password"
                                type="password"
                                required
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(event) =>
                                    form.setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    )}
                    {Object.entries(form.errors).map(([field, error]) => (
                        <p className="form-error" role="alert" key={field}>
                            {error}
                        </p>
                    ))}
                    <button
                        className="primary login-submit"
                        disabled={form.processing || enteringDemo}
                    >
                        {form.processing
                            ? 'Please wait…'
                            : registering
                              ? 'Create workspace →'
                              : 'Sign in →'}
                    </button>
                    <p className="account-switch">
                        {registering
                            ? 'Already have an account? '
                            : 'New to Ledgerly? '}
                        <button
                            type="button"
                            className="text-link"
                            onClick={() => {
                                setRegistering(!registering);
                                form.clearErrors();
                            }}
                        >
                            {registering ? 'Sign in' : 'Create a workspace'}
                        </button>
                    </p>
                    {demoAvailable && (
                        <>
                            <div className="divider">
                                or explore with sample data
                            </div>
                            <button
                                type="button"
                                className="demo-btn"
                                disabled={enteringDemo || form.processing}
                                onClick={() =>
                                    router.post(
                                        '/demo',
                                        {},
                                        {
                                            onStart: () =>
                                                setEnteringDemo(true),
                                            onFinish: () =>
                                                setEnteringDemo(false),
                                        },
                                    )
                                }
                            >
                                {enteringDemo
                                    ? 'Opening your workspace…'
                                    : 'Enter demo workspace →'}
                            </button>
                            <p className="login-note">
                                Explore fictional clients and records in a
                                private demo workspace.
                            </p>
                        </>
                    )}
                </form>
            </main>
        </div>
    );
}
