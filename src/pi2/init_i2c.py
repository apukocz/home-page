# Simple demo of of the PCA9685 PWM servo/LED controller library.
# This will move channel 0 from min to max position repeatedly.
# Author: Tony DiCola
# License: Public Domain
from __future__ import division
import time

# Import the PCA9685 module.
import Adafruit_PCA9685

# Alternatively specify a different address and/or bus:
pwm = Adafruit_PCA9685.PCA9685(address=0x40, busnum=1)

# Set frequency to 60hz, good for servos.
pwm.set_pwm_freq(60)

# Alternatively specify a different address and/or bus:
pwm = Adafruit_PCA9685.PCA9685(address=0x41, busnum=1)

# Set frequency to 60hz, good for servos.
pwm.set_pwm_freq(60)

