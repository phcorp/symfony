package org.symfony.tui;

import java.math.BigDecimal;
import java.math.RoundingMode;

final class Stepping {
  static String step(String text, int direction, Double min, Double max, double step) {
    double current;
    try {
      current = Double.parseDouble(text.trim());
      if (!Double.isFinite(current)) current = 0;
    } catch (NumberFormatException invalid) {
      current = 0;
    }
    if (!(step > 0) || !Double.isFinite(step)) step = 1;
    double base = min == null ? 0 : min;
    double units = (current - base) / step;
    double target =
        Math.abs(units - Math.rint(units)) < 1e-9
            ? Math.rint(units) + direction
            : direction > 0 ? Math.floor(units) + 1 : Math.ceil(units) - 1;
    double next = base + target * step;
    if (min != null) next = Math.max(min, next);
    if (max != null) next = Math.min(base + Math.floor((max - base) / step + 1e-9) * step, next);
    if (direction > 0 ? next < current : next > current) return text;
    int decimals = Math.max(0, Math.min(20, BigDecimal.valueOf(step).stripTrailingZeros().scale()));
    String value =
        BigDecimal.valueOf(next)
            .setScale(decimals, RoundingMode.HALF_UP)
            .stripTrailingZeros()
            .toPlainString();
    return value.equals("-0") ? "0" : value;
  }

  static double snap(double value, double min, double max, double step) {
    if (!(step > 0) || !Double.isFinite(step)) step = 1;
    double top = Math.max(min, max);
    double result = min + Math.round((Math.max(min, Math.min(top, value)) - min) / step) * step;
    if (result > top + 1e-9) result = min + Math.floor((top - min) / step + 1e-9) * step;
    return Math.rint(result * 1e10) / 1e10;
  }
}
