import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { logout } from '@/routes';

const OPEN_EVENT = 'kozy:logout-reminder';

/**
 * Ask for the reminder. The account menu that triggers it closes - and
 * unmounts - as soon as an item is picked, so the dialog lives in the app
 * shell and is reached through an event rather than local state.
 */
export function openLogoutReminder() {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

/**
 * Stops a guest who booked without choosing a password from logging out
 * without one. Their account only holds a random placeholder, so once they
 * leave they cannot sign back in to see or manage their bookings.
 */
export function LogoutReminder() {
    const [open, setOpen] = useState(false);
    const [leaving, setLeaving] = useState(false);

    useEffect(() => {
        const show = () => setOpen(true);

        window.addEventListener(OPEN_EVENT, show);

        return () => window.removeEventListener(OPEN_EVENT, show);
    }, []);

    const logOutAnyway = () => {
        setLeaving(true);
        router.flushAll();
        router.post(logout().url, {}, { onFinish: () => setLeaving(false) });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <div className="mb-2 flex size-10 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                        <KeyRound className="size-5" aria-hidden />
                    </div>
                    <DialogTitle>Choose a password before you go</DialogTitle>
                    <DialogDescription>
                        Your account doesn&apos;t have a password yet. Without
                        one you won&apos;t be able to log back in to see or
                        manage your bookings.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2 sm:justify-between">
                    <Button
                        variant="outline"
                        onClick={logOutAnyway}
                        disabled={leaving}
                    >
                        {leaving ? 'Logging out…' : 'Log out anyway'}
                    </Button>
                    <Button
                        onClick={() => {
                            setOpen(false);
                            router.visit('/client/auth/password');
                        }}
                        disabled={leaving}
                    >
                        Choose password
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
